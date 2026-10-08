<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Buyer;
use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\PriceHistory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Dompdf\Dompdf;
use Dompdf\Options;

class SaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Sale::query();

        // Apply filters
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        if ($request->filled('payment_status')) {
            $query->byPaymentStatus($request->payment_status);
        }

        if ($request->filled('buyer_id')) {
            $query->byBuyer($request->buyer_id);
        }

        if ($request->filled('sale_type')) {
            $query->bySaleType($request->sale_type);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);
            $query->byDateRange($startDate, $endDate);
        }

        if ($request->has('overdue') && $request->overdue) {
            $query->overdue();
        }

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Sorting
        $sortField = $request->get('sort', 'sale_date');
        $sortDirection = $request->get('direction', 'desc');
        
        $allowedSorts = ['sale_date', 'quantity_sold', 'price_per_unit', 'net_income', 'buyer_name', 'status', 'payment_status'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDirection);
        }

        // Include relationships
        $with = ['createdBy:id,name', 'buyer:id,name,type', 'deductions'];
        if ($request->has('include_approvals')) {
            $with[] = 'approvals.approvedBy:id,name';
        }
        $query->with($with);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $sales = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $sales,
            'meta' => [
                'total_pending' => Sale::pending()->count(),
                'total_approved' => Sale::approved()->count(),
                'total_overdue' => Sale::overdue()->count()
            ]
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_cycle_id' => 'nullable|uuid|exists:crop_cycles,id',
            'harvest_id' => 'nullable|uuid|exists:harvests,id',
            'sale_date' => 'required|date|before_or_equal:today',
            'quantity_sold' => 'required|numeric|min:0.001|max:999999.999',
            'unit' => 'required|string|max:50',
            'price_per_unit' => 'required|numeric|min:0.01|max:99999999.99',
            'buyer_id' => 'nullable|uuid|exists:buyers,id',
            'buyer_name' => 'required_without:buyer_id|string|max:255',
            'market_location' => 'nullable|string|max:255',
            'sale_type' => ['required', Rule::in(['farmgate', 'market', 'door_to_door', 'pickup', 'delivery', 'online', 'bulk_order', 'spot_sale'])],
            'payment_method' => ['required', Rule::in(['cash', 'm_pesa', 'bank_transfer', 'cheque', 'credit', 'mobile_money', 'crypto', 'other'])],
            'payment_status' => ['sometimes', Rule::in(['paid', 'pending', 'partial', 'overdue'])],
            'payment_due_date' => 'nullable|date|after:sale_date',
            'amount_paid' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'delivery_info' => 'nullable|array',
            'transport_cost' => 'nullable|numeric|min:0|max:999999.99',
            'quality_feedback' => 'nullable|array',
            'deductions' => 'nullable|array',
            'deductions.*.type' => ['required_with:deductions', Rule::in(['transport_fare', 'airtime', 'packaging', 'labour', 'handling_fees', 'market_fees', 'commission', 'taxes', 'storage', 'loading_offloading', 'weighing', 'grading_sorting', 'other'])],
            'deductions.*.amount' => 'required_with:deductions|numeric|min:0',
            'deductions.*.description' => 'nullable|string|max:255',
            'deductions.*.recipient' => 'nullable|string|max:255'
        ]);

        $grossIncome = $validated['quantity_sold'] * $validated['price_per_unit'];
        $amountPaid = $validated['amount_paid'] ?? 0;
        $deductionTotal = collect($validated['deductions'] ?? [])->sum('amount');
        $netIncome = $grossIncome - $deductionTotal;

        if ($netIncome < 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Deductions cannot exceed gross income',
            ], 422);
        }

        if ($amountPaid > $netIncome) {
            return response()->json([
                'status' => 'error',
                'message' => 'Amount paid cannot exceed net income',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $cropCycle = !empty($validated['crop_cycle_id'])
                ? CropCycle::find($validated['crop_cycle_id'])
                : null;
            $harvest = !empty($validated['harvest_id'])
                ? Harvest::lockForUpdate()->find($validated['harvest_id'])
                : null;

            if ($harvest) {
                if ($cropCycle && $harvest->crop_cycle_id !== $cropCycle->id) {
                    DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Selected harvest does not belong to the selected crop cycle',
                    ], 422);
                }

                $cropCycle ??= $harvest->cropCycle;
                if ($this->normalizeUnit($validated['unit']) !== $this->normalizeUnit($harvest->unit)) {
                    DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Sale unit must match the selected harvest unit',
                    ], 422);
                }

                $availableQuantity = $harvest->getRemainingQuantity();

                if ((float) $validated['quantity_sold'] > $availableQuantity) {
                    DB::rollBack();

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Not enough harvested stock',
                        'data' => ['available_quantity' => $availableQuantity],
                    ], 422);
                }
            }

            $activeFarmId = $request->header('X-Tenant-ID');
            $farmId = $cropCycle?->farm_id ?? $activeFarmId;
            if ($cropCycle && $activeFarmId && $activeFarmId !== $cropCycle->farm_id) {
                DB::rollBack();

                return response()->json([
                    'status' => 'error',
                    'message' => 'Selected crop cycle does not belong to the active farm',
                ], 422);
            }

            $buyer = !empty($validated['buyer_id'])
                ? Buyer::find($validated['buyer_id'])
                : null;
            if ($buyer && $validated['payment_method'] === 'credit' && !$buyer->canPurchase($grossIncome)) {
                DB::rollBack();

                return response()->json([
                    'status' => 'error',
                    'message' => 'Purchase amount exceeds buyer credit limit',
                    'data' => [
                        'credit_limit' => $buyer->credit_limit,
                        'outstanding_credit' => $buyer->getOutstandingCredit(),
                        'requested_amount' => $grossIncome
                    ]
                ], 400);
            }

            $deductions = $validated['deductions'] ?? [];
            unset($validated['deductions']);

            $saleData = array_merge($validated, [
                'crop_cycle_id' => $cropCycle?->id,
                'gross_income' => $grossIncome,
                'total_deductions' => 0,
                'net_income' => $grossIncome,
                'amount_outstanding' => $grossIncome,
                'created_by' => $request->user()->id,
                'status' => 'pending',
            ]);

            if ($buyer) {
                $saleData['buyer_name'] = $buyer->name;
                $saleData['is_recurring_buyer'] = $buyer->total_transactions > 0;
            }

            $sale = Sale::create($saleData);

            foreach ($deductions as $deduction) {
                $sale->deductions()->create([
                    'deduction_type' => $deduction['type'],
                    'amount' => $deduction['amount'],
                    'description' => $deduction['description'] ?? null,
                    'recipient' => $deduction['recipient'] ?? null,
                ]);
            }

            $sale->calculateTotals();

            if ($amountPaid > 0) {
                $sale->payments()->create([
                    'amount' => $amountPaid,
                    'payment_method' => $validated['payment_method'],
                    'payment_date' => $validated['sale_date'],
                    'reference' => null,
                    'notes' => 'Initial payment recorded with sale',
                    'recorded_by' => $request->user()->id,
                ]);
            }

            if ($this->canApproveSale($request, $farmId)) {
                $sale->approve($request->user()->id, 'Approved on entry by farm management');
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Sale recorded successfully',
                'data' => $sale->load(['createdBy:id,name', 'buyer:id,name', 'deductions'])
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function canApproveSale(Request $request, ?string $farmId): bool
    {
        if (!$farmId || !$request->user()) {
            return false;
        }

        return in_array($request->user()->getRoleOnFarm($farmId), ['owner', 'manager'], true);
    }

    private function normalizeUnit(?string $unit): string
    {
        return match (strtolower(trim((string) $unit))) {
            'kilogram', 'kilograms', 'kgs' => 'kg',
            'ton', 'tons', 'tonne' => 'tonnes',
            default => strtolower(trim((string) $unit)),
        };
    }

    public function show(string $id): JsonResponse
    {
        $sale = Sale::with([
            'createdBy:id,name',
            'buyer:id,name,type,location',
            'deductions',
            'approvals.approvedBy:id,name',
            'approvedBy:id,name',
            'payments.recordedBy:id,name'
        ])->findOrFail($id);

        $netIncome = (float) $sale->net_income;
        $analytics = [
            'deduction_rate' => (float) $sale->gross_income > 0
                ? round(((float) $sale->total_deductions / (float) $sale->gross_income) * 100, 2)
                : 0,
            'collection_rate' => $netIncome > 0
                ? round(((float) $sale->amount_paid / $netIncome) * 100, 2)
                : 0,
            'payment_count' => $sale->payments->count(),
            'outstanding_balance' => (float) $sale->amount_outstanding,
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'sale' => $sale,
                'analytics' => $analytics
            ]
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (!$this->canManageSales($request)) {
            return $this->managementRequired();
        }

        $validated = $request->validate([
            'crop_cycle_id' => 'sometimes|nullable|uuid|exists:crop_cycles,id',
            'harvest_id' => 'sometimes|nullable|uuid|exists:harvests,id',
            'sale_date' => 'sometimes|required|date|before_or_equal:today',
            'quantity_sold' => 'sometimes|required|numeric|min:0.001|max:999999.999',
            'unit' => 'sometimes|required|string|max:50',
            'price_per_unit' => 'sometimes|required|numeric|min:0.01|max:99999999.99',
            'buyer_id' => 'nullable|uuid|exists:buyers,id',
            'buyer_name' => 'sometimes|required|string|max:255',
            'market_location' => 'nullable|string|max:255',
            'sale_type' => ['sometimes', 'required', Rule::in(['farmgate', 'market', 'door_to_door', 'pickup', 'delivery', 'online', 'bulk_order', 'spot_sale'])],
            'payment_method' => ['sometimes', 'required', Rule::in(['cash', 'm_pesa', 'bank_transfer', 'cheque', 'credit', 'mobile_money', 'crypto', 'other'])],
            'payment_due_date' => 'nullable|date|after:sale_date',
            'notes' => 'nullable|string|max:1000',
            'delivery_info' => 'nullable|array',
            'transport_cost' => 'nullable|numeric|min:0|max:999999.99',
            'quality_feedback' => 'nullable|array',
            'deductions' => 'sometimes|array',
            'deductions.*.type' => ['required', Rule::in(['transport_fare', 'airtime', 'packaging', 'labour', 'handling_fees', 'market_fees', 'commission', 'taxes', 'storage', 'loading_offloading', 'weighing', 'grading_sorting', 'other'])],
            'deductions.*.amount' => 'required|numeric|min:0',
            'deductions.*.description' => 'nullable|string|max:255',
            'deductions.*.recipient' => 'nullable|string|max:255',
        ]);

        $sale = DB::transaction(function () use ($id, $validated) {
            $sale = Sale::lockForUpdate()->findOrFail($id);
            $oldBuyer = $sale->buyer;
            $harvestId = array_key_exists('harvest_id', $validated) ? $validated['harvest_id'] : $sale->harvest_id;
            $harvest = $harvestId ? Harvest::lockForUpdate()->findOrFail($harvestId) : null;
            $quantity = (float) ($validated['quantity_sold'] ?? $sale->quantity_sold);
            $unit = $validated['unit'] ?? $sale->unit;

            if ($harvest) {
                if ($this->normalizeUnit($unit) !== $this->normalizeUnit($harvest->unit)) {
                    abort(422, 'Sale unit must match the selected harvest unit');
                }

                $available = $harvest->getRemainingQuantity();
                if ($sale->harvest_id === $harvest->id && $sale->status !== 'rejected') {
                    $available += (float) $sale->quantity_sold;
                }
                if ($quantity > $available) {
                    abort(422, 'Not enough harvested stock. Available quantity: ' . $available);
                }
            }

            $deductions = $validated['deductions'] ?? null;
            $attributes = $validated;
            unset($attributes['deductions']);
            $sale->update($attributes);

            if ($deductions !== null) {
                $sale->deductions()->delete();
                foreach ($deductions as $deduction) {
                    $sale->deductions()->create([
                        'deduction_type' => $deduction['type'],
                        'amount' => $deduction['amount'],
                        'description' => $deduction['description'] ?? null,
                        'recipient' => $deduction['recipient'] ?? null,
                    ]);
                }
            }

            $sale->calculateTotals();
            if ($sale->status === 'approved') {
                $sale->createPriceHistoryEntry();
                $oldBuyer?->updateStatistics($sale);
                $sale->buyer?->updateStatistics($sale);
            }

            return $sale->fresh();
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Sale updated successfully',
            'data' => $sale->load(['createdBy:id,name', 'buyer:id,name', 'deductions'])
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $request = request();
        if (!$this->canManageSales($request)) {
            return $this->managementRequired();
        }

        DB::transaction(function () use ($id) {
            $sale = Sale::lockForUpdate()->findOrFail($id);
            $buyer = $sale->buyer;
            $sale->delete();
            $buyer?->updateStatistics($sale);
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Sale deleted successfully'
        ]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        if (!$this->canManageSales($request)) {
            return $this->managementRequired();
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
            'changes_made' => 'nullable|array'
        ]);

        [$sale, $approval] = DB::transaction(function () use ($id, $validated, $request) {
            $sale = Sale::lockForUpdate()->findOrFail($id);
            if ($sale->status !== 'pending') {
                abort(409, 'Only pending sales can be approved');
            }
            $approval = $sale->approve(
                $request->user()->id,
                $validated['reason'] ?? null,
                $validated['changes_made'] ?? null
            );

            return [$sale->fresh(), $approval];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Sale approved successfully',
            'data' => [
                'sale' => $sale->fresh(),
                'approval' => $approval
            ]
        ]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        if (!$this->canManageSales($request)) {
            return $this->managementRequired();
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        [$sale, $approval] = DB::transaction(function () use ($id, $validated, $request) {
            $sale = Sale::lockForUpdate()->findOrFail($id);
            if ($sale->status !== 'pending') {
                abort(409, 'Only pending sales can be rejected');
            }
            $approval = $sale->reject($request->user()->id, $validated['reason']);

            return [$sale->fresh(), $approval];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Sale rejected successfully',
            'data' => [
                'sale' => $sale->fresh(),
                'approval' => $approval
            ]
        ]);
    }

    public function recordPayment(Request $request, string $id): JsonResponse
    {
        if (!$this->canManageSales($request)) {
            return $this->managementRequired();
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => ['required', Rule::in(['cash', 'm_pesa', 'bank_transfer', 'cheque', 'mobile_money', 'other'])],
            'payment_date' => 'required|date|before_or_equal:today',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        [$sale, $payment] = DB::transaction(function () use ($id, $validated, $request) {
            $sale = Sale::lockForUpdate()->findOrFail($id);
            if ($sale->status !== 'approved') {
                abort(409, 'Payments can only be recorded against approved sales');
            }
            if ((float) $validated['amount'] > (float) $sale->amount_outstanding) {
                abort(422, 'Payment amount exceeds outstanding balance');
            }

            $payment = $sale->payments()->create([
                ...$validated,
                'recorded_by' => $request->user()->id,
            ]);
            $sale->recordPayment((float) $validated['amount']);

            return [$sale->fresh(), $payment->load('recordedBy:id,name')];
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Payment recorded successfully',
            'data' => [
                'sale' => $sale,
                'payment' => $payment,
                'new_balance' => $sale->amount_outstanding
            ]
        ]);
    }

    public function getSalesSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'buyer_id' => 'nullable|uuid|exists:buyers,id',
            'sale_type' => ['nullable', Rule::in(['farmgate', 'market', 'door_to_door', 'pickup', 'delivery', 'online', 'bulk_order', 'spot_sale'])],
            'payment_status' => ['nullable', Rule::in(['paid', 'pending', 'partial', 'overdue'])]
        ]);

        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);

        $filters = array_filter([
            'buyer_id' => $validated['buyer_id'] ?? null,
            'sale_type' => $validated['sale_type'] ?? null,
            'payment_status' => $validated['payment_status'] ?? null
        ]);

        $summary = Sale::getSalesSummary($startDate, $endDate, $filters);

        return response()->json([
            'status' => 'success',
            'data' => $summary,
            'meta' => [
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'days' => $startDate->diffInDays($endDate) + 1
                ],
                'filters' => $filters
            ]
        ]);
    }

    public function getSalesStats(): JsonResponse
    {
        $stats = [
            'total_sales' => Sale::count(),
            'pending_sales' => Sale::pending()->count(),
            'approved_sales' => Sale::approved()->count(),
            'rejected_sales' => Sale::where('status', 'rejected')->count(),
            'overdue_payments' => Sale::overdue()->count(),
            'total_revenue' => Sale::approved()->sum('net_income'),
            'monthly_revenue' => Sale::approved()
                ->whereMonth('sale_date', now()->month)
                ->sum('net_income'),
            'by_payment_status' => Sale::approved()
                ->selectRaw('payment_status, COUNT(*) as count, SUM(net_income) as total_value')
                ->groupBy('payment_status')
                ->get()
                ->mapWithKeys(function($item) {
                    return [$item->payment_status => [
                        'count' => $item->count,
                        'total_value' => $item->total_value
                    ]];
                })->toArray()
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    public function getSalesAnalytics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'buyer_id' => 'nullable|uuid|exists:buyers,id',
            'sale_type' => ['nullable', Rule::in(['farmgate', 'market', 'door_to_door', 'pickup', 'delivery', 'online', 'bulk_order', 'spot_sale'])],
            'payment_status' => ['nullable', Rule::in(['paid', 'pending', 'partial', 'overdue'])],
        ]);

        $endDate = isset($validated['end_date']) ? Carbon::parse($validated['end_date'])->endOfDay() : now()->endOfDay();
        $startDate = isset($validated['start_date']) ? Carbon::parse($validated['start_date'])->startOfDay() : $endDate->copy()->subDays(29)->startOfDay();
        $periodDays = max(1, $startDate->diffInDays($endDate) + 1);

        $baseQuery = Sale::approved()->whereBetween('sale_date', [$startDate->toDateString(), $endDate->toDateString()]);
        foreach (['buyer_id', 'sale_type', 'payment_status'] as $filter) {
            if (!empty($validated[$filter])) {
                $baseQuery->where($filter, $validated[$filter]);
            }
        }

        $previousEnd = $startDate->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($periodDays - 1)->startOfDay();
        $previousQuery = Sale::approved()->whereBetween('sale_date', [$previousStart->toDateString(), $previousEnd->toDateString()]);
        foreach (['buyer_id', 'sale_type', 'payment_status'] as $filter) {
            if (!empty($validated[$filter])) {
                $previousQuery->where($filter, $validated[$filter]);
            }
        }

        $sales = (clone $baseQuery)->get();
        $previousSales = $previousQuery->get();
        $totalRevenue = (float) $sales->sum('net_income');
        $previousRevenue = (float) $previousSales->sum('net_income');
        $averageSaleValue = $sales->count() > 0 ? $totalRevenue / $sales->count() : 0;
        $previousAverage = $previousSales->count() > 0 ? (float) $previousSales->sum('net_income') / $previousSales->count() : 0;
        $grossRevenue = (float) $sales->sum('gross_income');
        $totalDeductions = (float) $sales->sum('total_deductions');
        $paidAmount = (float) $sales->sum('amount_paid');

        $kpis = [
            'totalRevenue' => round($totalRevenue, 2),
            'revenueGrowth' => $this->percentageChange($totalRevenue, $previousRevenue),
            'averageSaleValue' => round($averageSaleValue, 2),
            'avgValueGrowth' => $this->percentageChange($averageSaleValue, $previousAverage),
            'activeBuyers' => $sales->whereNotNull('buyer_id')->pluck('buyer_id')->unique()->count(),
            'newBuyers' => Buyer::whereBetween('created_at', [$startDate, $endDate])->count(),
            'totalQuantity' => round((float) $sales->sum('quantity_sold'), 3),
            'totalTransactions' => $sales->count(),
        ];

        $analytics = [
            'kpis' => $kpis,
            'revenue_trends' => [
                'daily' => $this->getRevenueTrend($baseQuery, 'day'),
                'weekly' => $this->getRevenueTrend($baseQuery, 'week'),
                'monthly' => $this->getRevenueTrend($baseQuery, 'month'),
            ],
            'sale_type_distribution' => $this->getGroupedSalesAnalytics($baseQuery, 'sale_type'),
            'payment_status_distribution' => $this->getGroupedSalesAnalytics($baseQuery, 'payment_status', true),
            'monthly_performance' => $this->getRevenueTrend($baseQuery, 'month'),
            'top_buyers' => (clone $baseQuery)
                ->selectRaw('COALESCE(buyer_id::text, buyer_name) as buyer_key, buyer_id, buyer_name, COUNT(*) as transactions, SUM(net_income) as revenue, AVG(net_income) as avg_order_value')
                ->groupBy('buyer_key', 'buyer_id', 'buyer_name')
                ->orderByDesc('revenue')
                ->limit(5)
                ->get()
                ->map(fn ($buyer) => [
                    'id' => $buyer->buyer_id ?? $buyer->buyer_key,
                    'name' => $buyer->buyer_name ?? 'Walk-in buyer',
                    'transactions' => (int) $buyer->transactions,
                    'revenue' => round((float) $buyer->revenue, 2),
                    'avgOrder' => round((float) $buyer->avg_order_value, 2),
                ])
                ->values(),
            'profitability' => [
                'grossMargin' => $grossRevenue > 0 ? round(($totalRevenue / $grossRevenue) * 100, 2) : 0,
                'netMargin' => $grossRevenue > 0 ? round((($totalRevenue - $totalDeductions) / $grossRevenue) * 100, 2) : 0,
                'avgDeductionRate' => $grossRevenue > 0 ? round(($totalDeductions / $grossRevenue) * 100, 2) : 0,
                'totalProfit' => round($totalRevenue, 2),
            ],
            'insights' => $this->buildSalesInsights($kpis, $paidAmount, $totalRevenue),
            'recommendations' => $this->buildSalesRecommendations($kpis, $paidAmount, $totalRevenue),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $analytics,
            'meta' => [
                'period' => [
                    'start_date' => $startDate->toDateString(),
                    'end_date' => $endDate->toDateString(),
                    'days' => $periodDays,
                ],
                'filters' => array_filter($validated),
            ],
        ]);
    }

    public function export(Request $request)
    {
        if (!$this->canManageSales($request)) {
            return $this->managementRequired();
        }

        $validated = $request->validate([
            'format' => 'nullable|in:csv,pdf',
            'status' => 'nullable|in:pending,approved,rejected',
            'payment_status' => 'nullable|in:paid,pending,partial,overdue',
            'buyer_id' => 'nullable|uuid|exists:buyers,id',
            'sale_type' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'sale_id' => 'nullable|uuid|exists:sales,id',
            'sale_ids' => 'nullable|array|max:100',
            'sale_ids.*' => 'uuid|exists:sales,id',
        ]);

        $format = $validated['format'] ?? 'csv';
        $query = Sale::with(['buyer:id,name', 'createdBy:id,name'])->orderByDesc('sale_date');
        foreach (['status', 'payment_status', 'buyer_id', 'sale_type'] as $filter) {
            if (!empty($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }
        if (!empty($validated['start_date'])) {
            $query->whereDate('sale_date', '>=', $validated['start_date']);
        }
        if (!empty($validated['end_date'])) {
            $query->whereDate('sale_date', '<=', $validated['end_date']);
        }
        if (!empty($validated['sale_id'])) {
            $query->whereKey($validated['sale_id']);
        }
        if (!empty($validated['sale_ids'])) {
            $query->whereKey($validated['sale_ids']);
        }

        $sales = $query->get();
        $filename = 'sales-' . now()->format('Y-m-d-His') . '.' . $format;

        if ($format === 'pdf') {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('exports.sales', [
                'sales' => $sales,
                'generatedAt' => now(),
                'total' => (float) $sales->sum('net_income'),
            ])->render());
            $pdf->setPaper('a4', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Date', 'Buyer', 'Quantity', 'Unit', 'Unit Price', 'Gross Income', 'Deductions', 'Net Income', 'Payment Status', 'Approval Status']);
        foreach ($sales as $sale) {
            fputcsv($stream, [
                $sale->sale_date->toDateString(),
                $sale->buyer?->name ?? $sale->buyer_name,
                $sale->quantity_sold,
                $sale->unit,
                $sale->price_per_unit,
                $sale->gross_income,
                $sale->total_deductions,
                $sale->net_income,
                $sale->payment_status,
                $sale->status,
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response("\xEF\xBB\xBF" . $csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function exportSummary(Request $request)
    {
        $request->merge(['format' => $request->query('format', 'pdf')]);

        return $this->export($request);
    }

    private function canManageSales(Request $request): bool
    {
        $farmId = $request->header('X-Tenant-ID');
        $role = $farmId ? $request->user()?->getRoleOnFarm($farmId) : null;

        return in_array($role, ['owner', 'manager'], true);
    }

    private function managementRequired(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Only farm owners and managers can perform this action',
        ], 403);
    }

    private function percentageChange(float $current, float $previous): float
    {
        if ($previous == 0.0) {
            return $current > 0.0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }

    private function getRevenueTrend($query, string $unit)
    {
        return (clone $query)
            ->selectRaw("date_trunc(?, sale_date::timestamp) as period, SUM(net_income) as revenue", [$unit])
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($item) => [
                'label' => Carbon::parse($item->period)->format($unit === 'month' ? 'M Y' : 'M d'),
                'value' => round((float) $item->revenue, 2),
            ])
            ->values();
    }

    private function getGroupedSalesAnalytics($query, string $column, bool $includePaid = false)
    {
        $total = (clone $query)->count();

        return (clone $query)
            ->select($column)
            ->selectRaw('COUNT(*) as count, SUM(net_income) as value' . ($includePaid ? ', SUM(amount_paid) as paid_value' : ''))
            ->groupBy($column)
            ->orderByDesc('value')
            ->get()
            ->map(function ($item) use ($column, $total, $includePaid) {
                $data = [
                    'name' => $item->{$column},
                    'count' => (int) $item->count,
                    'value' => round((float) $item->value, 2),
                    'percentage' => $total > 0 ? round(((int) $item->count / $total) * 100, 2) : 0,
                ];

                if ($includePaid) {
                    $data['paid_value'] = round((float) $item->paid_value, 2);
                }

                return $data;
            })
            ->values();
    }

    private function buildSalesInsights(array $kpis, float $paidAmount, float $totalRevenue): array
    {
        $collectionRate = $totalRevenue > 0 ? round(($paidAmount / $totalRevenue) * 100, 2) : 0;

        return [
            [
                'id' => 'revenue-growth',
                'type' => $kpis['revenueGrowth'] >= 0 ? 'positive' : 'warning',
                'title' => 'Revenue trend',
                'description' => "Revenue changed by {$kpis['revenueGrowth']}% compared with the previous period.",
            ],
            [
                'id' => 'buyer-activity',
                'type' => 'info',
                'title' => 'Buyer activity',
                'description' => "{$kpis['activeBuyers']} buyers purchased in this period, including {$kpis['newBuyers']} new buyer records.",
            ],
            [
                'id' => 'collection-rate',
                'type' => $collectionRate >= 85 ? 'positive' : 'warning',
                'title' => 'Collection rate',
                'description' => "{$collectionRate}% of period revenue has been collected.",
            ],
        ];
    }

    private function buildSalesRecommendations(array $kpis, float $paidAmount, float $totalRevenue): array
    {
        $collectionRate = $totalRevenue > 0 ? round(($paidAmount / $totalRevenue) * 100, 2) : 0;

        return [
            [
                'id' => 'collections',
                'type' => $collectionRate >= 85 ? 'positive' : 'warning',
                'title' => $collectionRate >= 85 ? 'Maintain collections cadence' : 'Prioritize outstanding balances',
                'description' => $collectionRate >= 85
                    ? 'Current collections are healthy for the selected period.'
                    : 'Follow up on pending and partial payments before adding more credit exposure.',
            ],
            [
                'id' => 'buyers',
                'type' => 'info',
                'title' => 'Review top buyers',
                'description' => 'Use buyer revenue concentration to plan retention and credit-limit decisions.',
            ],
            [
                'id' => 'pricing',
                'type' => $kpis['avgValueGrowth'] >= 0 ? 'positive' : 'warning',
                'title' => 'Review average sale value',
                'description' => "Average sale value changed by {$kpis['avgValueGrowth']}% against the previous period.",
            ],
        ];
    }
}
