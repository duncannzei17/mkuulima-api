<?php

namespace App\Http\Controllers;

use App\Models\CropCycle;
use App\Models\CropTask;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Farm;
use App\Models\Harvest;
use App\Models\LabourEntry;
use App\Models\MarketPrice;
use App\Models\PendingPayment;
use App\Models\Sale;
use App\Models\TenantRegistry;
use App\Models\UssdRequest;
use App\Models\UssdSession;
use App\Models\User;
use App\Models\WeatherAlert;
use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UssdController extends Controller
{
    private const MENU_AUTH = 'auth';
    private const MENU_PAYMENT = 'payment';
    private const SESSION_SECONDS = 90;

    public function handleUssdRequest(Request $request): Response
    {
        if (! $this->callbackAuthorized($request)) {
            return $this->plain('END Service unavailable.', 403);
        }

        $validator = Validator::make($request->all(), [
            'sessionId' => 'required_without:session_id|string|max:100',
            'session_id' => 'required_without:sessionId|string|max:100',
            'phoneNumber' => 'required_without:phone|string|max:30',
            'phone' => 'required_without:phoneNumber|string|max:30',
            'text' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->plain('END Invalid USSD request.', 422);
        }

        $sessionId = trim((string) ($request->input('sessionId') ?? $request->input('session_id')));
        $phone = $this->normalizePhoneNumber((string) ($request->input('phoneNumber') ?? $request->input('phone')));
        $rawText = trim((string) $request->input('text', ''));
        $requestLog = null;

        try {
            $user = User::query()->active()->where('phone', $phone)->first();
            if (! $user) {
                return $this->plain("END Simu hii haijasajiliwa FarmOS.\nWasiliana na msaada.");
            }

            $farm = $this->resolveFarm($user);
            if (! $farm) {
                return $this->plain("END Hakuna shamba hai kwa simu hii.\nFungua FarmOS kwanza.");
            }

            $tenant = TenantRegistry::query()
                ->where('farm_id', $farm->id)
                ->where('status', 'active')
                ->first();

            if (! $tenant) {
                return $this->plain('END Huduma ya shamba haijawa tayari. Jaribu baadaye.');
            }

            $tenant->switchToTenant();

            $session = UssdSession::query()->where('session_id', $sessionId)->first();
            if ($session && $session->phone_number !== $phone) {
                Log::warning('USSD session phone mismatch', ['session_id' => $sessionId]);
                return $this->plain('END Ombi halikuthibitishwa.', 403);
            }

            if ($session && $session->status !== UssdSession::STATUS_ACTIVE) {
                return $this->plain($session->last_output ?: 'END Kipindi hiki kimekwisha. Piga tena.');
            }

            if ($session && $session->isExpired()) {
                $session->update([
                    'status' => UssdSession::STATUS_EXPIRED,
                    'completion_reason' => 'timeout',
                    'completed_successfully' => false,
                ]);

                return $this->plain('END Muda umeisha. Piga tena kuanza upya.');
            }

            if (! $session) {
                $session = UssdSession::query()->create([
                    'farm_id' => $farm->id,
                    'user_id' => $user->id,
                    'session_id' => $sessionId,
                    'phone_number' => $phone,
                    'network_code' => $request->input('networkCode') ?: $this->detectNetwork($phone),
                    'status' => UssdSession::STATUS_ACTIVE,
                    'current_menu' => self::MENU_AUTH,
                    'current_step' => 1,
                    'language' => data_get($user->preferences, 'ussd_language', UssdSession::LANG_SWAHILI),
                    'started_at' => now(),
                    'last_activity_at' => now(),
                    'expires_at' => now()->addSeconds(self::SESSION_SECONDS),
                ]);
            }

            $requestId = (string) ($request->input('requestId') ?? $request->input('request_id', ''));
            $duplicate = $requestId !== ''
                ? $session->requests()->where('request_id', $requestId)->exists()
                : (str_contains($rawText, '*') && $session->last_input === $rawText);

            if ($duplicate && $session->last_output) {
                return $this->plain($session->last_output);
            }

            $requestLog = UssdRequest::query()->create([
                'session_id' => $session->id,
                'request_id' => $requestId ?: null,
                'sequence_number' => $session->total_requests + 1,
                'phone_number' => $phone,
                'network_code' => $session->network_code,
                'user_input' => $rawText,
                'raw_request' => json_encode($request->except(['apiKey', 'token']), JSON_UNESCAPED_SLASHES),
                'received_at' => now(),
            ]);

            $input = $this->latestInput($rawText);
            $output = $this->process($session, $requestLog, $user, $farm, $input);
            $this->recordResponse($requestLog, $output);

            $session->forceFill([
                'last_input' => $rawText,
                'last_output' => $output,
                'last_activity_at' => now(),
                'expires_at' => now()->addSeconds(self::SESSION_SECONDS),
                'total_requests' => $session->total_requests + 1,
            ])->save();

            return $this->plain($output);
        } catch (\Throwable $error) {
            if ($requestLog) {
                try {
                    $requestLog->refresh()->forceFill([
                        'has_error' => true,
                        'error_type' => UssdRequest::ERROR_SYSTEM,
                        'error_message' => $error->getMessage(),
                        'outcome' => UssdRequest::OUTCOME_ERROR,
                        'responded_at' => now(),
                    ])->save();
                } catch (\Throwable $loggingError) {
                    Log::error('USSD request error logging failed', ['error' => $loggingError->getMessage()]);
                }
            }

            Log::error('USSD processing failed', [
                'session_id' => $sessionId,
                'phone' => $this->maskPhone($phone),
                'error' => $error->getMessage(),
            ]);

            return $this->plain('END Samahani, huduma haikukamilika. Jaribu tena.');
        }
    }

    private function process(
        UssdSession $session,
        UssdRequest $request,
        User $user,
        Farm $farm,
        string $input
    ): string {
        if ($session->current_menu === self::MENU_AUTH) {
            return $this->authenticate($session, $request, $user, $input);
        }

        return match ($session->current_menu) {
            UssdSession::MENU_MAIN => $this->mainMenu($session, $request, $user, $farm, $input),
            UssdSession::MENU_EXPENSES => $this->expenseFlow($session, $request, $user, $farm, $input),
            UssdSession::MENU_LABOUR => $this->labourFlow($session, $request, $user, $farm, $input),
            UssdSession::MENU_HARVEST => $this->harvestFlow($session, $request, $user, $farm, $input),
            UssdSession::MENU_SALES => $this->salesSummary($session, $request, $farm),
            UssdSession::MENU_MARKET_PRICE => $this->marketPrices($session, $request),
            UssdSession::MENU_BALANCE => $this->balance($session, $request, $farm),
            UssdSession::MENU_ALERTS => $this->alerts($session, $request, $farm),
            UssdSession::MENU_TASKS => $this->tasks($session, $request, $farm),
            self::MENU_PAYMENT => $this->paymentFlow($session, $request, $user, $farm, $input),
            UssdSession::MENU_HELP => $this->helpFlow($session, $user, $input),
            default => $this->resetToMain($session),
        };
    }

    private function authenticate(UssdSession $session, UssdRequest $request, User $user, string $input): string
    {
        if ($input === '') {
            if (! $user->ussd_pin_hash) {
                $session->completeSession('pin_not_configured');

                return "END Weka PIN ya USSD kwenye FarmOS kwanza.\nSet a USSD PIN in FarmOS first.";
            }

            return "CON Karibu FarmOS\nIngiza PIN / Enter PIN:";
        }

        if ($user->ussd_locked_until && now()->isBefore($user->ussd_locked_until)) {
            $session->completeSession('account_locked');

            return 'END PIN imefungwa kwa muda. Jaribu baada ya dakika 5.';
        }

        if (! preg_match('/^\d{4,6}$/', $input) || ! Hash::check($input, $user->ussd_pin_hash)) {
            $attempts = (int) $user->ussd_failed_attempts + 1;
            $user->forceFill([
                'ussd_failed_attempts' => $attempts >= 3 ? 0 : $attempts,
                'ussd_locked_until' => $attempts >= 3 ? now()->addMinutes(5) : null,
            ])->save();
            $request->forceFill([
                'has_error' => true,
                'error_type' => UssdRequest::ERROR_AUTHENTICATION,
                'outcome' => UssdRequest::OUTCOME_VALIDATION_FAILED,
            ])->save();

            if ($attempts >= 3) {
                $session->completeSession('pin_locked');

                return 'END PIN si sahihi. Akaunti imefungwa dakika 5.';
            }

            return 'CON PIN si sahihi. Jaribu tena:';
        }

        $user->forceFill(['ussd_failed_attempts' => 0, 'ussd_locked_until' => null])->save();
        $session->forceFill(['is_authenticated' => true])->save();
        $session->setMenu(UssdSession::MENU_MAIN);

        return $this->mainText($session);
    }

    private function mainMenu(UssdSession $session, UssdRequest $request, User $user, Farm $farm, string $input): string
    {
        if ($input === '') {
            return $this->mainText($session);
        }

        $menu = match ($input) {
            '1' => UssdSession::MENU_EXPENSES,
            '2' => UssdSession::MENU_LABOUR,
            '3' => UssdSession::MENU_HARVEST,
            '4' => UssdSession::MENU_SALES,
            '5' => UssdSession::MENU_MARKET_PRICE,
            '6' => UssdSession::MENU_BALANCE,
            '7' => UssdSession::MENU_ALERTS,
            '8' => UssdSession::MENU_TASKS,
            '9' => self::MENU_PAYMENT,
            '10' => UssdSession::MENU_HELP,
            default => null,
        };

        if ($input === '0') {
            $session->completeSession('user_exit');

            return $this->tr($session, 'END Asante kwa kutumia FarmOS.', 'END Thank you for using FarmOS.');
        }

        if (! $menu) {
            return $this->tr($session, 'CON Chaguo si sahihi.\n', 'CON Invalid option.\n').substr($this->mainText($session), 4);
        }

        $session->setMenu($menu, 1);

        return match ($menu) {
            UssdSession::MENU_EXPENSES => $this->expenseCategoryText($session),
            UssdSession::MENU_LABOUR => $this->labourTypeText($session),
            UssdSession::MENU_HARVEST => $this->cropText($session),
            UssdSession::MENU_SALES => $this->salesSummary($session, $request, $farm),
            UssdSession::MENU_MARKET_PRICE => $this->marketPrices($session, $request),
            UssdSession::MENU_BALANCE => $this->balance($session, $request, $farm),
            UssdSession::MENU_ALERTS => $this->alerts($session, $request, $farm),
            UssdSession::MENU_TASKS => $this->tasks($session, $request, $farm),
            self::MENU_PAYMENT => $this->workerText($session),
            default => $this->helpText($session),
        };
    }

    private function expenseFlow(UssdSession $session, UssdRequest $request, User $user, Farm $farm, string $input): string
    {
        if ($input === '0') {
            return $this->resetToMain($session);
        }

        if ($session->current_step === 1) {
            $categories = ExpenseCategory::query()->active()->orderBy('name')->limit(7)->get(['id', 'name']);
            if ($categories->isEmpty()) {
                return $this->end($session, 'no_expense_categories', 'Hakuna aina za matumizi.', 'No expense categories are available.');
            }

            if ($input === '') {
                return $this->expenseCategoryText($session, $categories);
            }

            $category = $categories->values()->get(((int) $input) - 1);
            if (! $category) {
                return $this->invalid($session, $this->expenseCategoryText($session, $categories));
            }

            $session->setTempData('category_id', $category->id);
            $session->setTempData('category_name', $category->name);
            $session->nextStep();

            return $this->tr($session, 'CON Ingiza kiasi KES:', 'CON Enter amount in KES:');
        }

        if ($session->current_step === 2) {
            $amount = $this->validAmount($input);
            if ($amount === null) {
                return $this->tr($session, 'CON Kiasi si sahihi. Ingiza 1-1000000:', 'CON Invalid amount. Enter 1-1000000:');
            }

            $session->setTempData('amount', $amount);
            $session->nextStep();

            return $this->tr(
                $session,
                'CON Thibitisha '.$session->getTempData('category_name').' KES '.number_format($amount)."\n1. Hifadhi\n0. Futa",
                'CON Confirm '.$session->getTempData('category_name').' KES '.number_format($amount)."\n1. Save\n0. Cancel"
            );
        }

        if ($session->current_step === 3 && $input === '1') {
            $role = $user->getRoleOnFarm($farm->id);
            $approved = in_array($role, ['owner', 'manager'], true);
            $expense = DB::transaction(function () use ($session, $user, $approved) {
                $expense = Expense::query()->create([
                    'expense_date' => now()->toDateString(),
                    'expense_time' => now()->format('H:i:s'),
                    'amount' => $session->getTempData('amount'),
                    'description' => 'Recorded through USSD',
                    'category_id' => $session->getTempData('category_id'),
                    'payment_method' => 'cash',
                    'status' => $approved ? 'approved' : 'pending',
                    'created_by' => $user->id,
                    'approved_by' => $approved ? $user->id : null,
                    'approved_at' => $approved ? now() : null,
                    'metadata' => ['entry_method' => 'ussd', 'ussd_session_id' => $session->id],
                ]);

                if ($approved) {
                    ExpenseCategory::query()->find($session->getTempData('category_id'))?->updateUsageStats();
                }

                return $expense;
            });

            $this->action($request, 'expense_created', ['expense_id' => $expense->id]);

            return $this->end($session, 'expense_completed', 'Matumizi yamehifadhiwa: KES '.number_format((float) $expense->amount), 'Expense saved: KES '.number_format((float) $expense->amount), ['expense_id' => $expense->id]);
        }

        return $this->invalid($session, $this->tr($session, 'CON Chagua 1 kuhifadhi au 0 kufuta.', 'CON Choose 1 to save or 0 to cancel.'));
    }

    private function labourFlow(UssdSession $session, UssdRequest $request, User $user, Farm $farm, string $input): string
    {
        if ($input === '0') {
            return $this->resetToMain($session);
        }

        $types = [
            '1' => ['weeding', 'Kupalilia', 'Weeding'],
            '2' => ['bed_preparation', 'Kuandaa vitalu', 'Bed preparation'],
            '3' => ['transplanting', 'Kupanda', 'Transplanting'],
            '4' => ['watering', 'Kumwagilia', 'Watering'],
            '5' => ['spraying', 'Kunyunyizia', 'Spraying'],
            '6' => ['harvest_labour', 'Kuvuna', 'Harvesting'],
            '7' => ['general_work', 'Kazi nyingine', 'General work'],
        ];

        if ($session->current_step === 1) {
            if (! isset($types[$input])) {
                return $input === '' ? $this->labourTypeText($session) : $this->invalid($session, $this->labourTypeText($session));
            }
            $session->setTempData('labour_type', $types[$input][0]);
            $session->setTempData('labour_label', $this->tr($session, $types[$input][1], $types[$input][2]));
            $session->nextStep();

            return $this->tr($session, 'CON Wafanyakazi wangapi? (1-100):', 'CON Number of workers? (1-100):');
        }

        if ($session->current_step === 2) {
            if (! ctype_digit($input) || (int) $input < 1 || (int) $input > 100) {
                return $this->tr($session, 'CON Ingiza idadi 1-100:', 'CON Enter a number from 1-100:');
            }
            $session->setTempData('workers', (int) $input);
            $session->nextStep();

            return $this->tr($session, 'CON Ingiza jumla iliyolipwa KES:', 'CON Enter total paid in KES:');
        }

        if ($session->current_step === 3) {
            $amount = $this->validAmount($input);
            if ($amount === null) {
                return $this->tr($session, 'CON Kiasi si sahihi. Jaribu tena:', 'CON Invalid amount. Try again:');
            }
            $session->setTempData('amount', $amount);
            $session->nextStep();

            return $this->tr($session, 'CON Thibitisha '.$session->getTempData('labour_label').', watu '.$session->getTempData('workers').', KES '.number_format($amount)."\n1. Hifadhi\n0. Futa", 'CON Confirm '.$session->getTempData('labour_label').', '.$session->getTempData('workers').' workers, KES '.number_format($amount)."\n1. Save\n0. Cancel");
        }

        if ($session->current_step === 4 && $input === '1') {
            $workers = (int) $session->getTempData('workers');
            $entry = DB::transaction(function () use ($session, $user, $farm, $workers) {
                $entry = LabourEntry::query()->create([
                    'worker_name' => $workers === 1 ? 'USSD farm worker' : "USSD group ({$workers})",
                    'labour_date' => now()->toDateString(),
                    'labour_type' => $session->getTempData('labour_type'),
                    'payment_type' => $workers > 1 ? 'group_labour' : 'daily',
                    'amount' => $session->getTempData('amount'),
                    'number_of_workers' => $workers,
                    'description' => 'Recorded through USSD',
                    'status' => 'pending',
                    'payment_status' => 'paid',
                    'payment_method' => 'cash',
                    'paid_amount' => $session->getTempData('amount'),
                    'payment_date' => now()->toDateString(),
                    'created_by' => $user->id,
                ]);

                if (in_array($user->getRoleOnFarm($farm->id), ['owner', 'manager'], true)) {
                    $entry->approve($user->id, 'Recorded through authenticated USSD');
                }

                return $entry->fresh();
            });
            $this->action($request, 'labour_created', ['labour_entry_id' => $entry->id]);

            return $this->end($session, 'labour_completed', 'Kazi imehifadhiwa: KES '.number_format((float) $entry->amount), 'Labour saved: KES '.number_format((float) $entry->amount), ['labour_entry_id' => $entry->id]);
        }

        return $this->invalid($session, $this->tr($session, 'CON Chagua 1 kuhifadhi au 0 kufuta.', 'CON Choose 1 to save or 0 to cancel.'));
    }

    private function harvestFlow(UssdSession $session, UssdRequest $request, User $user, Farm $farm, string $input): string
    {
        if ($input === '0') {
            return $this->resetToMain($session);
        }

        if ($session->current_step === 1) {
            $crops = CropCycle::query()->where('farm_id', $farm->id)->active()->orderBy('crop_name')->limit(7)->get();
            if ($crops->isEmpty()) {
                return $this->end($session, 'no_active_crops', 'Hakuna zao hai la kuvuna.', 'There is no active crop to harvest.');
            }
            if ($input === '') {
                return $this->cropText($session, $crops);
            }
            $crop = $crops->values()->get(((int) $input) - 1);
            if (! $crop) {
                return $this->invalid($session, $this->cropText($session, $crops));
            }
            $session->setTempData('crop_cycle_id', $crop->id);
            $session->setTempData('crop_name', $crop->crop_name);
            $session->setTempData('unit', $crop->yield_unit ?: 'kg');
            $session->nextStep();

            return $this->tr($session, 'CON Ingiza kiasi ('.$session->getTempData('unit').'):', 'CON Enter quantity ('.$session->getTempData('unit').'):');
        }

        if ($session->current_step === 2) {
            $quantity = $this->validAmount($input, 10000000);
            if ($quantity === null) {
                return $this->tr($session, 'CON Kiasi si sahihi. Jaribu tena:', 'CON Invalid quantity. Try again:');
            }
            $session->setTempData('quantity', $quantity);
            $session->nextStep();

            return $this->tr($session, 'CON Thibitisha '.$session->getTempData('crop_name').' '.number_format($quantity, 2).' '.$session->getTempData('unit')."\n1. Hifadhi\n0. Futa", 'CON Confirm '.$session->getTempData('crop_name').' '.number_format($quantity, 2).' '.$session->getTempData('unit')."\n1. Save\n0. Cancel");
        }

        if ($session->current_step === 3 && $input === '1') {
            $harvest = DB::transaction(function () use ($session, $user, $farm) {
                $harvest = Harvest::query()->create([
                    'crop_cycle_id' => $session->getTempData('crop_cycle_id'),
                    'harvest_date' => now()->toDateString(),
                    'total_quantity' => $session->getTempData('quantity'),
                    'unit' => $session->getTempData('unit'),
                    'grade_type' => 'simple',
                    'simple_grade' => 'marketable',
                    'notes' => 'Recorded through USSD',
                    'status' => 'pending',
                    'created_by' => $user->id,
                ]);

                if (in_array($user->getRoleOnFarm($farm->id), ['owner', 'manager'], true)) {
                    $harvest->approve($user->id);
                }

                return $harvest->fresh();
            });
            $this->action($request, 'harvest_created', ['harvest_id' => $harvest->id]);

            return $this->end($session, 'harvest_completed', 'Mavuno yamehifadhiwa: '.number_format((float) $harvest->total_quantity, 2).' '.$harvest->unit, 'Harvest saved: '.number_format((float) $harvest->total_quantity, 2).' '.$harvest->unit, ['harvest_id' => $harvest->id]);
        }

        return $this->invalid($session, $this->tr($session, 'CON Chagua 1 kuhifadhi au 0 kufuta.', 'CON Choose 1 to save or 0 to cancel.'));
    }

    private function salesSummary(UssdSession $session, UssdRequest $request, Farm $farm): string
    {
        $query = Sale::query()->approved()->whereHas('cropCycle', fn ($q) => $q->where('farm_id', $farm->id));
        $today = (clone $query)->whereDate('sale_date', today())->sum('net_income');
        $week = (clone $query)->whereBetween('sale_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])->sum('net_income');
        $month = (clone $query)->whereMonth('sale_date', now()->month)->whereYear('sale_date', now()->year)->sum('net_income');
        $this->action($request, 'sales_summary_viewed');

        return $this->end($session, 'sales_viewed', "Leo KES ".number_format((float) $today)."\nWiki KES ".number_format((float) $week)."\nMwezi KES ".number_format((float) $month), "Today KES ".number_format((float) $today)."\nWeek KES ".number_format((float) $week)."\nMonth KES ".number_format((float) $month));
    }

    private function marketPrices(UssdSession $session, UssdRequest $request): string
    {
        $prices = MarketPrice::query()->orderByDesc('price_date')->orderByDesc('created_at')->get()->unique('crop_name')->take(4);
        if ($prices->isEmpty()) {
            return $this->end($session, 'prices_empty', 'Bei za soko hazijapatikana.', 'Market prices are not available.');
        }
        $lines = $prices->map(fn ($price) => Str::limit($price->crop_name, 18, '').': '.number_format((float) $price->min_price, 0).'-'.number_format((float) $price->max_price, 0).' KES/'.$price->unit)->implode("\n");
        $this->action($request, 'market_prices_viewed');

        return $this->end($session, 'prices_viewed', $lines, $lines);
    }

    private function balance(UssdSession $session, UssdRequest $request, Farm $farm): string
    {
        $income = Sale::query()->approved()->whereHas('cropCycle', fn ($q) => $q->where('farm_id', $farm->id))->sum('net_income');
        $expenses = Expense::query()->approved()->sum('amount');
        $profit = (float) $income - (float) $expenses;
        $this->action($request, 'balance_viewed');

        return $this->end($session, 'balance_viewed', 'Mapato KES '.number_format((float) $income)."\nMatumizi KES ".number_format((float) $expenses)."\nSalio KES ".number_format($profit), 'Income KES '.number_format((float) $income)."\nExpenses KES ".number_format((float) $expenses)."\nBalance KES ".number_format($profit));
    }

    private function alerts(UssdSession $session, UssdRequest $request, Farm $farm): string
    {
        $alert = WeatherAlert::query()->where('farm_id', $farm->id)->active()->notExpired()->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")->latest('triggered_at')->first();
        $message = $alert ? Str::limit($alert->title.': '.$alert->message, 145, '') : $this->tr($session, 'Hakuna tahadhari hai ya hali hatari.', 'There are no active weather alerts.');
        $this->action($request, 'alerts_viewed', ['alert_id' => $alert?->id]);

        return $this->finish($session, 'alerts_viewed', 'END '.$message);
    }

    private function tasks(UssdSession $session, UssdRequest $request, Farm $farm): string
    {
        $tasks = CropTask::query()->dueToday()->whereHas('cropCycle', fn ($q) => $q->where('farm_id', $farm->id))->orderBy('scheduled_time')->limit(4)->get();
        $message = $tasks->isEmpty()
            ? $this->tr($session, 'Hakuna kazi za leo.', 'No tasks are due today.')
            : $tasks->values()->map(fn ($task, $index) => ($index + 1).'. '.Str::limit($task->task_name, 28, ''))->implode("\n");
        $this->action($request, 'tasks_viewed');

        return $this->end($session, 'tasks_viewed', $message, $message);
    }

    private function paymentFlow(UssdSession $session, UssdRequest $request, User $user, Farm $farm, string $input): string
    {
        if ($input === '0') {
            return $this->resetToMain($session);
        }

        if ($session->current_step === 1) {
            $workers = Worker::query()->active()->whereNotNull('phone')->orderBy('name')->limit(7)->get();
            if ($workers->isEmpty()) {
                return $this->end($session, 'no_workers', 'Hakuna mfanyakazi mwenye simu.', 'No worker with a phone number is available.');
            }
            if ($input === '') {
                return $this->workerText($session, $workers);
            }
            $worker = $workers->values()->get(((int) $input) - 1);
            if (! $worker) {
                return $this->invalid($session, $this->workerText($session, $workers));
            }
            $session->setTempData('worker_id', $worker->id);
            $session->setTempData('worker_name', $worker->name);
            $session->setTempData('worker_phone', $this->normalizePhoneNumber($worker->phone));
            $session->nextStep();

            return $this->tr($session, 'CON Ingiza kiasi KES:', 'CON Enter amount in KES:');
        }

        if ($session->current_step === 2) {
            $amount = $this->validAmount($input);
            if ($amount === null) {
                return $this->tr($session, 'CON Kiasi si sahihi. Jaribu tena:', 'CON Invalid amount. Try again:');
            }
            $session->setTempData('amount', $amount);
            $session->nextStep();

            return $this->tr($session, 'CON Lipa '.$session->getTempData('worker_name').' KES '.number_format($amount)."?\n1. Ndiyo\n0. Futa", 'CON Pay '.$session->getTempData('worker_name').' KES '.number_format($amount)."?\n1. Yes\n0. Cancel");
        }

        if ($session->current_step === 3 && $input === '1') {
            $session->nextStep();

            return $this->tr($session, 'CON Thibitisha tena kwa PIN:', 'CON Confirm again with your PIN:');
        }

        if ($session->current_step === 4) {
            if (! preg_match('/^\d{4,6}$/', $input) || ! Hash::check($input, $user->ussd_pin_hash)) {
                return $this->tr($session, 'CON PIN si sahihi. Jaribu tena au 0 kufuta:', 'CON Invalid PIN. Try again or 0 to cancel:');
            }

            $role = $user->getRoleOnFarm($farm->id);
            $amount = (float) $session->getTempData('amount');
            $status = $role === 'owner' && $amount <= 2000 ? 'approved' : ($role === 'worker' ? 'pending_manager_approval' : 'pending_owner_approval');
            $payment = PendingPayment::query()->create([
                'payment_type' => 'labour_payment',
                'amount' => $amount,
                'payment_reason' => 'Worker payment requested through USSD',
                'recipient_name' => $session->getTempData('worker_name'),
                'recipient_phone' => $session->getTempData('worker_phone'),
                'preferred_payment_method' => 'mpesa',
                'priority' => 'medium',
                'linked_id' => $session->getTempData('worker_id'),
                'linked_metadata' => ['source' => 'ussd', 'worker_id' => $session->getTempData('worker_id')],
                'requested_by_user_id' => $user->id,
                'status' => $status,
            ]);
            $this->action($request, 'payment_requested', ['payment_id' => $payment->id, 'status' => $status]);

            return $this->end($session, 'payment_requested', 'Ombi '.$payment->payment_reference.' limehifadhiwa.', 'Request '.$payment->payment_reference.' has been saved.', ['payment_id' => $payment->id]);
        }

        return $this->invalid($session, $this->tr($session, 'CON Chagua 1 kuendelea au 0 kufuta.', 'CON Choose 1 to continue or 0 to cancel.'));
    }

    private function helpFlow(UssdSession $session, User $user, string $input): string
    {
        if ($input === '') {
            return $this->helpText($session);
        }
        if ($input === '0') {
            return $this->resetToMain($session);
        }
        if (in_array($input, ['1', '2'], true)) {
            $language = $input === '1' ? UssdSession::LANG_SWAHILI : UssdSession::LANG_ENGLISH;
            $preferences = $user->preferences ?? [];
            $preferences['ussd_language'] = $language;
            $user->forceFill(['preferences' => $preferences])->save();
            $session->forceFill(['language' => $language])->save();

            return $this->resetToMain($session);
        }

        return $this->invalid($session, $this->helpText($session));
    }

    public function getSettings(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => (bool) $user->ussd_pin_hash,
                'enabled_at' => $user->ussd_pin_enabled_at,
                'locked_until' => $user->ussd_locked_until,
                'phone' => $this->maskPhone((string) $user->phone),
                'language' => data_get($user->preferences, 'ussd_language', UssdSession::LANG_SWAHILI),
                'callback_protected' => filled(config('services.ussd.callback_secret')),
            ],
        ]);
    }

    public function setPin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string|current_password',
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/', 'confirmed'],
            'language' => 'sometimes|in:sw,en',
        ]);
        $user = $request->user();
        $preferences = $user->preferences ?? [];
        $preferences['ussd_language'] = $validated['language'] ?? ($preferences['ussd_language'] ?? UssdSession::LANG_SWAHILI);
        if ($request->header('X-Tenant-ID')) {
            $preferences['ussd_farm_id'] = $request->header('X-Tenant-ID');
        }
        $user->forceFill([
            'ussd_pin_hash' => Hash::make($validated['pin']),
            'ussd_pin_enabled_at' => now(),
            'ussd_failed_attempts' => 0,
            'ussd_locked_until' => null,
            'preferences' => $preferences,
        ])->save();

        return response()->json(['success' => true, 'message' => 'USSD PIN updated successfully.']);
    }

    public function disablePin(Request $request): JsonResponse
    {
        $request->validate(['current_password' => 'required|string|current_password']);
        $request->user()->forceFill([
            'ussd_pin_hash' => null,
            'ussd_pin_enabled_at' => null,
            'ussd_failed_attempts' => 0,
            'ussd_locked_until' => null,
        ])->save();

        return response()->json(['success' => true, 'message' => 'USSD access disabled.']);
    }

    public function getSessions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|in:active,expired,completed,abandoned',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);
        $query = UssdSession::query()->where('farm_id', $request->header('X-Tenant-ID'))->latest('started_at');
        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        $sessions = $query->paginate($validated['per_page'] ?? 20);
        $sessions->getCollection()->transform(fn (UssdSession $session) => [
            'id' => $session->id,
            'session_id' => $session->session_id,
            'phone' => $this->maskPhone($session->phone_number),
            'status' => $session->status,
            'current_menu' => $session->current_menu,
            'total_requests' => $session->total_requests,
            'completed_successfully' => $session->completed_successfully,
            'completion_reason' => $session->completion_reason,
            'duration_seconds' => $session->duration_seconds,
            'started_at' => $session->started_at,
            'last_activity_at' => $session->last_activity_at,
        ]);

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    public function getSession(Request $request, string $sessionId): JsonResponse
    {
        $session = UssdSession::query()
            ->where('farm_id', $request->header('X-Tenant-ID'))
            ->where(fn ($query) => $query->where('id', $sessionId)->orWhere('session_id', $sessionId))
            ->with(['requests' => fn ($query) => $query->orderBy('sequence_number')])
            ->firstOrFail();
        $session->phone_number = $this->maskPhone($session->phone_number);
        $session->makeHidden(['pin_hash', 'temp_data', 'context_data']);

        return response()->json(['success' => true, 'data' => $session]);
    }

    public function getAnalytics(Request $request): JsonResponse
    {
        $days = min(90, max(1, (int) $request->query('days', 7)));
        $sessions = UssdSession::query()->where('farm_id', $request->header('X-Tenant-ID'))->where('started_at', '>=', now()->subDays($days));
        $requests = UssdRequest::query()->whereHas('session', fn ($query) => $query->where('farm_id', $request->header('X-Tenant-ID')))->where('received_at', '>=', now()->subDays($days));

        return response()->json(['success' => true, 'data' => [
            'period_days' => $days,
            'sessions' => (clone $sessions)->count(),
            'successful_sessions' => (clone $sessions)->where('completed_successfully', true)->count(),
            'active_sessions' => (clone $sessions)->where('status', UssdSession::STATUS_ACTIVE)->count(),
            'requests' => (clone $requests)->count(),
            'error_requests' => (clone $requests)->where('has_error', true)->count(),
            'average_response_ms' => round((float) (clone $requests)->avg('processing_time_ms'), 1),
            'slow_requests' => (clone $requests)->where('processing_time_ms', '>', 3000)->count(),
        ]]);
    }

    public function testFlow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => 'required|string|max:100',
            'text' => 'nullable|string|max:1000',
        ]);
        $simulated = Request::create('/api/ussd/callback', 'POST', [
            'sessionId' => 'test-'.$request->user()->id.'-'.$validated['session_id'],
            'phoneNumber' => $request->user()->phone,
            'text' => $validated['text'] ?? '',
            'networkCode' => 'simulator',
        ]);
        if (filled(config('services.ussd.callback_secret'))) {
            $simulated->headers->set('X-USSD-Secret', (string) config('services.ussd.callback_secret'));
        }
        $response = $this->handleUssdRequest($simulated);

        return response()->json(['success' => $response->getStatusCode() === 200, 'data' => ['output' => $response->getContent()]], $response->getStatusCode());
    }

    private function mainText(UssdSession $session): string
    {
        return $this->tr($session,
            "CON Karibu FarmOS\n1.Matumizi 2.Kazi 3.Mavuno\n4.Mapato 5.Bei 6.Mizania\n7.Taarifa 8.Ratiba 9.Lipa\n10.Lugha/Msaada 0.Toka",
            "CON FarmOS\n1.Expense 2.Labour 3.Harvest\n4.Income 5.Prices 6.Balance\n7.Alerts 8.Tasks 9.Pay worker\n10.Language/Help 0.Exit"
        );
    }

    private function expenseCategoryText(UssdSession $session, $categories = null): string
    {
        $categories ??= ExpenseCategory::query()->active()->orderBy('name')->limit(7)->get(['id', 'name']);
        $lines = $categories->values()->map(fn ($category, $index) => ($index + 1).'. '.Str::limit($category->name, 22, ''))->implode("\n");

        return $this->tr($session, "CON Chagua aina ya matumizi:\n{$lines}\n0. Rudi", "CON Select expense category:\n{$lines}\n0. Back");
    }

    private function labourTypeText(UssdSession $session): string
    {
        return $this->tr($session, "CON Chagua kazi:\n1.Palilia 2.Andaa vitalu\n3.Panda 4.Mwagilia 5.Nyunyizia\n6.Vuna 7.Nyingine 0.Rudi", "CON Select labour:\n1.Weeding 2.Bed prep\n3.Transplant 4.Water 5.Spray\n6.Harvest 7.Other 0.Back");
    }

    private function cropText(UssdSession $session, $crops = null): string
    {
        $crops ??= CropCycle::query()->where('farm_id', $session->farm_id)->active()->orderBy('crop_name')->limit(7)->get();
        $lines = $crops->values()->map(fn ($crop, $index) => ($index + 1).'. '.Str::limit($crop->crop_name, 24, ''))->implode("\n");

        return $this->tr($session, "CON Chagua zao:\n{$lines}\n0. Rudi", "CON Select crop:\n{$lines}\n0. Back");
    }

    private function workerText(UssdSession $session, $workers = null): string
    {
        $workers ??= Worker::query()->active()->whereNotNull('phone')->orderBy('name')->limit(7)->get();
        $lines = $workers->values()->map(fn ($worker, $index) => ($index + 1).'. '.Str::limit($worker->name, 24, ''))->implode("\n");

        return $this->tr($session, "CON Chagua mfanyakazi:\n{$lines}\n0. Rudi", "CON Select worker:\n{$lines}\n0. Back");
    }

    private function helpText(UssdSession $session): string
    {
        $support = config('services.ussd.support_phone');

        return "CON Lugha / Language\n1. Kiswahili\n2. English\nMsaada / Help: {$support}\n0. Rudi / Back";
    }

    private function resetToMain(UssdSession $session): string
    {
        $session->clearTempData();
        $session->setMenu(UssdSession::MENU_MAIN);

        return $this->mainText($session);
    }

    private function invalid(UssdSession $session, string $menu): string
    {
        return $this->tr($session, "CON Chaguo si sahihi.\n", "CON Invalid option.\n").preg_replace('/^CON\s/', '', $menu);
    }

    private function end(UssdSession $session, string $reason, string $sw, string $en, array $data = []): string
    {
        return $this->finish($session, $reason, 'END '.$this->tr($session, $sw, $en), $data);
    }

    private function finish(UssdSession $session, string $reason, string $output, array $data = []): string
    {
        $session->forceFill([
            'status' => UssdSession::STATUS_COMPLETED,
            'completion_reason' => $reason,
            'completion_data' => $data,
            'completed_successfully' => true,
            'duration_seconds' => $session->started_at ? (int) round($session->started_at->diffInSeconds(now())) : null,
        ])->save();

        return Str::limit($output, 160, '');
    }

    private function tr(UssdSession $session, string $sw, string $en): string
    {
        return $session->language === UssdSession::LANG_ENGLISH ? $en : $sw;
    }

    private function action(UssdRequest $request, string $action, array $data = []): void
    {
        $request->forceFill(['action_taken' => $action, 'action_data' => $data])->save();
    }

    private function recordResponse(UssdRequest $request, string $output): void
    {
        $type = str_starts_with($output, 'END ') ? UssdRequest::RESPONSE_END : UssdRequest::RESPONSE_CONTINUE;
        $request->forceFill([
            'system_response' => $output,
            'response_type' => $type,
            'display_text' => preg_replace('/^(CON|END)\s/', '', $output),
            'processed_at' => now(),
            'responded_at' => now(),
            'processing_time_ms' => (int) round(max(0, $request->received_at?->diffInMilliseconds(now()) ?? 0)),
            'outcome' => $type === UssdRequest::RESPONSE_END && $request->has_error ? UssdRequest::OUTCOME_ERROR : ($request->action_taken ? UssdRequest::OUTCOME_SUCCESS : null),
        ])->save();
    }

    private function resolveFarm(User $user): ?Farm
    {
        $preferred = data_get($user->preferences, 'ussd_farm_id');
        if ($preferred && $user->hasAccessToFarm($preferred)) {
            $farm = Farm::query()->active()->find($preferred);
            if ($farm) {
                return $farm;
            }
        }

        return $user->ownedFarms()->active()->oldest()->first()
            ?? $user->farms()->where('farms.status', 'active')->wherePivot('status', 'active')->oldest('farms.created_at')->first();
    }

    private function callbackAuthorized(Request $request): bool
    {
        $secret = (string) config('services.ussd.callback_secret', '');
        if ($secret === '') {
            return ! app()->environment('production');
        }

        $provided = (string) ($request->header('X-USSD-Secret') ?: $request->input('apiKey', ''));

        return $provided !== '' && hash_equals($secret, $provided);
    }

    private function latestInput(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return trim((string) collect(explode('*', $text))->last());
    }

    private function validAmount(string $input, float $max = 1000000): ?float
    {
        if (! is_numeric($input)) {
            return null;
        }
        $value = (float) $input;

        return $value > 0 && $value <= $max ? $value : null;
    }

    private function normalizePhoneNumber(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (str_starts_with($digits, '0')) {
            $digits = '254'.substr($digits, 1);
        } elseif (! str_starts_with($digits, '254')) {
            $digits = '254'.$digits;
        }

        if (! preg_match('/^254[17]\d{8}$/', $digits)) {
            throw ValidationException::withMessages(['phoneNumber' => ['Invalid Kenyan phone number.']]);
        }

        return '+'.$digits;
    }

    private function detectNetwork(string $phone): string
    {
        $digits = ltrim($phone, '+');
        if (preg_match('/^254(?:70|71|72|74|75|76|79|110|111)/', $digits)) {
            return 'safaricom';
        }
        if (preg_match('/^254(?:73|78|100|101|102)/', $digits)) {
            return 'airtel';
        }
        if (preg_match('/^25477/', $digits)) {
            return 'telkom';
        }

        return 'unknown';
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) > 7 ? substr($phone, 0, 4).'***'.substr($phone, -3) : '***';
    }

    private function plain(string $content, int $status = 200): Response
    {
        return response($content, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
