<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\FarmWallet;
use App\Models\MpesaLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'type' => 'nullable|in:income,expense',
            'category' => 'nullable|string',
            'payment_method' => 'nullable|in:cash,mpesa,bank_transfer,cheque,mobile_money',
            'status' => 'nullable|in:draft,pending_approval,approved,processing,completed,failed,cancelled,partially_paid,refunded',
            'linked_type' => 'nullable|in:expense,labour,sale,inventory,supplier_payment',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'sort_by' => 'nullable|in:transaction_date,amount,created_at',
            'sort_direction' => 'nullable|in:asc,desc'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $query = Transaction::query()
            ->with(['createdBy:id,name', 'approvedBy:id,name', 'mpesaLog']);

        // Apply filters
        if ($request->start_date) {
            $query->where('transaction_date', '>=', $request->start_date);
        }
        if ($request->end_date) {
            $query->where('transaction_date', '<=', $request->end_date);
        }
        if ($request->type) {
            $query->where('type', $request->type);
        }
        if ($request->category) {
            $query->where('category', $request->category);
        }
        if ($request->payment_method) {
            $query->where('payment_method', $request->payment_method);
        }
        if ($request->status) {
            $query->where('status', $request->status);
        }
        if ($request->linked_type) {
            $query->where('linked_type', $request->linked_type);
        }

        // Apply sorting
        $sortBy = $request->get('sort_by', 'transaction_date');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        // Paginate results
        $perPage = $request->get('per_page', 20);
        $transactions = $query->paginate($perPage);

        // Add computed fields
        $transactions->getCollection()->transform(function ($transaction) {
            $transaction->status_color = $transaction->getStatusColor();
            $transaction->is_successful = $transaction->isSuccessful();
            $transaction->requires_approval = $transaction->requiresApproval();
            return $transaction;
        });

        return response()->json([
            'success' => true,
            'data' => $transactions
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:income,expense',
            'category' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,mpesa,bank_transfer,cheque,mobile_money',
            'description' => 'required|string|max:1000',
            'transaction_date' => 'nullable|date|before_or_equal:now',
            'phone_number' => ['nullable', 'string', 'regex:/^(?:\+254|254|0)?[17]\d{8}$/'],
            'linked_id' => 'nullable|uuid',
            'linked_type' => 'nullable|in:expense,labour,sale,inventory,supplier_payment',
            'fees' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'exchange_rate' => 'nullable|numeric|min:0',
            'metadata' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $transaction = Transaction::create([
                'type' => $request->type,
                'category' => $request->category,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'description' => $request->description,
                'transaction_date' => $request->transaction_date ?? now(),
                'linked_id' => $request->linked_id,
                'linked_type' => $request->linked_type,
                'phone_number' => $request->phone_number,
                'fees' => $request->fees ?? 0,
                'currency' => $request->currency ?? 'KES',
                'exchange_rate' => $request->exchange_rate ?? 1.0,
                'metadata' => $request->metadata,
                'status' => $this->determineInitialStatus($request),
                'created_by_user_id' => Auth::id(),
            ]);

            // Auto-approve if user has permission and amount is below threshold
            if ($this->shouldAutoApprove($request)) {
                $transaction->approve(Auth::user(), 'Auto-approved');
                if ($transaction->payment_method !== 'mpesa') {
                    $transaction->status = 'processing';
                    $transaction->save();
                    $transaction->markCompleted();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $transaction->load(['createdBy:id,name', 'mpesaLog']),
                'message' => 'Transaction created successfully'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error creating transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(Transaction $transaction): JsonResponse
    {
        $this->authorize('view', $transaction);

        $transaction->load([
            'createdBy:id,name,email',
            'approvedBy:id,name,email',
            'reconciledBy:id,name,email',
            'voidedBy:id,name,email',
            'mpesaLog',
            'linkedRecord'
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'transaction' => $transaction,
                'status_color' => $transaction->getStatusColor(),
                'is_successful' => $transaction->isSuccessful(),
                'requires_approval' => $transaction->requiresApproval(),
                'can_be_approved' => $transaction->canBeApproved(),
                'can_be_voided' => $transaction->canBeVoided()
            ]
        ]);
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('update', $transaction);

        if (!in_array($transaction->status, ['draft', 'pending_approval'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only draft and pending transactions can be updated'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'category' => 'sometimes|string|max:255',
            'amount' => 'sometimes|numeric|min:0.01',
            'description' => 'sometimes|string|max:1000',
            'transaction_date' => 'sometimes|date|before_or_equal:now',
            'phone_number' => ['sometimes', 'nullable', 'string', 'regex:/^(?:\+254|254|0)?[17]\d{8}$/'],
            'fees' => 'sometimes|numeric|min:0',
            'metadata' => 'sometimes|array'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $transaction->update($request->only([
                'category', 'amount', 'description', 'transaction_date',
                'phone_number', 'fees', 'metadata'
            ]));

            // Recalculate net amount if amount or fees changed
            if ($request->has(['amount', 'fees'])) {
                $transaction->net_amount = $transaction->amount - $transaction->fees;
                $transaction->save();
            }

            return response()->json([
                'success' => true,
                'data' => $transaction->fresh(['createdBy:id,name', 'mpesaLog']),
                'message' => 'Transaction updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    public function approve(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('approve', $transaction);

        if (!$transaction->canBeApproved()) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction cannot be approved in its current state'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'approval_notes' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $success = $transaction->approve(Auth::user(), $request->approval_notes);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'data' => $transaction->fresh(['approvedBy:id,name']),
                    'message' => 'Transaction approved successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to approve transaction'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error approving transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    public function void(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('void', $transaction);

        if (!$transaction->canBeVoided()) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction cannot be voided in its current state'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'void_reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $success = $transaction->void(Auth::user(), $request->void_reason);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'data' => $transaction->fresh(['voidedBy:id,name']),
                    'message' => 'Transaction voided successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to void transaction'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error voiding transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    public function reconcile(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorize('reconcile', $transaction);

        if ($transaction->is_reconciled) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction is already reconciled'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'reconciliation_notes' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $success = $transaction->reconcile(Auth::user(), $request->reconciliation_notes);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'data' => $transaction->fresh(['reconciledBy:id,name']),
                    'message' => 'Transaction reconciled successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to reconcile transaction'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error reconciling transaction: ' . $e->getMessage()
            ], 500);
        }
    }

    public function cashflowSummary(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'period' => 'nullable|in:today,week,month,quarter,year'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $dates = $this->getDateRange($request);
            $summary = Transaction::getCashflowSummary($dates['start'], $dates['end']);

            // Add period comparison
            $previousDates = $this->getPreviousPeriod($dates);
            $previousSummary = Transaction::getCashflowSummary($previousDates['start'], $previousDates['end']);

            $comparison = [
                'income_change' => $previousSummary['total_income'] > 0 
                    ? (($summary['total_income'] - $previousSummary['total_income']) / $previousSummary['total_income']) * 100 
                    : 0,
                'expense_change' => $previousSummary['total_expenses'] > 0 
                    ? (($summary['total_expenses'] - $previousSummary['total_expenses']) / $previousSummary['total_expenses']) * 100 
                    : 0,
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'current_period' => $summary,
                    'previous_period' => $previousSummary,
                    'comparison' => $comparison,
                    'period' => $request->get('period', 'custom'),
                    'date_range' => $dates
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating cashflow summary: ' . $e->getMessage()
            ], 500);
        }
    }

    public function outstandingPayments(): JsonResponse
    {
        try {
            $outstanding = Transaction::getOutstandingPayments();

            return response()->json([
                'success' => true,
                'data' => $outstanding
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching outstanding payments: ' . $e->getMessage()
            ], 500);
        }
    }

    public function categories(): JsonResponse
    {
        $categories = [
            'income' => [
                'sales' => 'Sales Revenue',
                'investment' => 'Investment',
                'grant_funding' => 'Grant Funding',
                'loan_received' => 'Loan Received',
                'miscellaneous' => 'Other Income'
            ],
            'expense' => [
                'labour_payment' => 'Labour Payment',
                'supplier_payment' => 'Supplier Payment',
                'inventory_purchase' => 'Inventory Purchase',
                'equipment_purchase' => 'Equipment Purchase',
                'fuel_transport' => 'Fuel & Transport',
                'utilities' => 'Utilities',
                'rent_fees' => 'Rent & Fees',
                'insurance' => 'Insurance',
                'loan_payment' => 'Loan Payment',
                'tax_payment' => 'Tax Payment',
                'refund' => 'Refund',
                'miscellaneous' => 'Miscellaneous'
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'period' => 'nullable|in:week,month,quarter,year',
            'group_by' => 'nullable|in:day,week,month',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $period = $request->get('period', 'month');
            $groupBy = $request->get('group_by', 'day');
            
            $dates = $this->getDateRange($request);
            
            $analytics = [
                'cashflow_trend' => $this->getCashflowTrend($dates, $groupBy),
                'category_breakdown' => $this->getCategoryBreakdown($dates),
                'payment_method_distribution' => $this->getPaymentMethodDistribution($dates),
                'transaction_volume' => $this->getTransactionVolume($dates),
                'success_rate' => $this->getSuccessRate($dates),
            ];

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error generating analytics: ' . $e->getMessage()
            ], 500);
        }
    }

    protected function determineInitialStatus(Request $request): string
    {
        // Check if auto-approval conditions are met
        if ($this->shouldAutoApprove($request)) {
            return 'pending_approval';
        }

        // Check if approval is required based on amount or category
        if ($request->amount > 10000 || in_array($request->category, ['equipment_purchase', 'loan_payment'])) {
            return 'pending_approval';
        }

        return 'draft';
    }

    protected function shouldAutoApprove(Request $request): bool
    {
        $user = Auth::user();
        $role = $user->getRoleOnFarm($request->header('X-Tenant-ID'));
        
        // Farm owners can auto-approve smaller amounts
        if ($role === 'owner' && $request->amount <= 5000) {
            return true;
        }

        // Managers can auto-approve very small amounts
        if ($role === 'manager' && $request->amount <= 1000) {
            return true;
        }

        return false;
    }

    protected function getDateRange(Request $request): array
    {
        if ($request->start_date && $request->end_date) {
            return [
                'start' => Carbon::parse($request->start_date)->startOfDay(),
                'end' => Carbon::parse($request->end_date)->endOfDay()
            ];
        }

        $period = $request->get('period', 'month');
        
        return match($period) {
            'today' => [
                'start' => Carbon::today()->startOfDay(),
                'end' => Carbon::today()->endOfDay()
            ],
            'week' => [
                'start' => Carbon::now()->startOfWeek(),
                'end' => Carbon::now()->endOfWeek()
            ],
            'month' => [
                'start' => Carbon::now()->startOfMonth(),
                'end' => Carbon::now()->endOfMonth()
            ],
            'quarter' => [
                'start' => Carbon::now()->startOfQuarter(),
                'end' => Carbon::now()->endOfQuarter()
            ],
            'year' => [
                'start' => Carbon::now()->startOfYear(),
                'end' => Carbon::now()->endOfYear()
            ],
            default => [
                'start' => Carbon::now()->startOfMonth(),
                'end' => Carbon::now()->endOfMonth()
            ]
        };
    }

    protected function getPreviousPeriod(array $currentDates): array
    {
        $diff = $currentDates['start']->diffInDays($currentDates['end']) + 1;
        
        return [
            'start' => $currentDates['start']->copy()->subDays($diff),
            'end' => $currentDates['start']->copy()->subDay()
        ];
    }

    protected function getCashflowTrend(array $dates, string $groupBy): array
    {
        $wallet = FarmWallet::where('farm_id', request()->header('X-Tenant-ID'))->first();
        return $wallet ? $wallet->getCashflowTrend($dates['start']->diffInDays($dates['end'])) : [];
    }

    protected function getCategoryBreakdown(array $dates): array
    {
        return Transaction::selectRaw('category, type, SUM(net_amount) as total')
                         ->where('status', 'completed')
                         ->whereBetween('transaction_date', [$dates['start'], $dates['end']])
                         ->groupBy('category', 'type')
                         ->get()
                         ->groupBy('type')
                         ->toArray();
    }

    protected function getPaymentMethodDistribution(array $dates): array
    {
        return Transaction::selectRaw('payment_method, COUNT(*) as count, SUM(net_amount) as total')
                         ->where('status', 'completed')
                         ->whereBetween('transaction_date', [$dates['start'], $dates['end']])
                         ->groupBy('payment_method')
                         ->get()
                         ->toArray();
    }

    protected function getTransactionVolume(array $dates): array
    {
        $total = Transaction::whereBetween('transaction_date', [$dates['start'], $dates['end']])->count();
        $completed = Transaction::where('status', 'completed')
                               ->whereBetween('transaction_date', [$dates['start'], $dates['end']])
                               ->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $total - $completed,
            'completion_rate' => $total > 0 ? ($completed / $total) * 100 : 0
        ];
    }

    protected function getSuccessRate(array $dates): array
    {
        $mpesaStats = MpesaLog::getTransactionStats($dates['start'], $dates['end']);
        
        return [
            'mpesa_success_rate' => $mpesaStats['success_rate'],
            'overall_completion_rate' => $this->getTransactionVolume($dates)['completion_rate'],
        ];
    }
}
