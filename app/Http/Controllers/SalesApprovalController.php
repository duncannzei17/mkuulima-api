<?php

namespace App\Http\Controllers;

use App\Models\SalesApproval;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Closure;

class SalesApprovalController extends Controller
{
    public function __construct()
    {
        $this->middleware(function (Request $request, Closure $next) {
            return $this->canManage($request) ? $next($request) : $this->managementRequired();
        });
    }

    public function index(Request $request): JsonResponse
    {
        $query = SalesApproval::query();

        // Apply filters
        if ($request->has('action')) {
            $query->byAction($request->action);
        }

        if ($request->has('approved_by')) {
            $query->byApprover($request->approved_by);
        }

        if ($request->has('days')) {
            $query->recent($request->days);
        }

        // Sorting
        $sortField = $request->get('sort', 'action_taken_at');
        $sortDirection = $request->get('direction', 'desc');
        
        $allowedSorts = ['action_taken_at', 'action', 'approved_by'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDirection);
        }

        // Include relationships
        $with = [
            'sale:id,buyer_name,net_income,sale_date,status',
            'approvedBy:id,name,email'
        ];
        $query->with($with);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $approvals = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $approvals,
            'meta' => [
                'total_approvals' => SalesApproval::approved()->count(),
                'total_rejections' => SalesApproval::rejected()->count(),
                'recent_activity' => SalesApproval::recent(7)->count()
            ]
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $approval = SalesApproval::with([
            'sale:id,buyer_name,net_income,sale_date,status,created_at,created_by',
            'sale.createdBy:id,name',
            'approvedBy:id,name,email'
        ])->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $approval
        ]);
    }

    public function getApprovalStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365'
        ]);

        $stats = SalesApproval::getApprovalStats($validated['days'] ?? 30);

        return response()->json([
            'status' => 'success',
            'data' => $stats,
            'meta' => [
                'period_days' => $validated['days'] ?? 30,
                'generated_at' => now()->toISOString()
            ]
        ]);
    }

    public function getApproverMetrics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365'
        ]);

        $metrics = SalesApproval::getApproverMetrics($validated['days'] ?? 30);

        return response()->json([
            'status' => 'success',
            'data' => $metrics,
            'meta' => [
                'period_days' => $validated['days'] ?? 30,
                'generated_at' => now()->toISOString()
            ]
        ]);
    }

    public function getRejectionReasons(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365'
        ]);

        $reasons = SalesApproval::getCommonRejectionReasons($validated['days'] ?? 90);

        return response()->json([
            'status' => 'success',
            'data' => $reasons,
            'meta' => [
                'period_days' => $validated['days'] ?? 90,
                'generated_at' => now()->toISOString()
            ]
        ]);
    }

    public function getWorkflowInsights(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'nullable|integer|min:1|max:365'
        ]);

        $insights = SalesApproval::getWorkflowInsights($validated['days'] ?? 30);

        return response()->json([
            'status' => 'success',
            'data' => $insights,
            'meta' => [
                'period_days' => $validated['days'] ?? 30,
                'generated_at' => now()->toISOString()
            ]
        ]);
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        if (!$this->canManage($request)) {
            return $this->managementRequired();
        }

        $validated = $request->validate([
            'sale_ids' => 'required|array|min:1|max:50',
            'sale_ids.*' => 'required|uuid|exists:sales,id',
            'reason' => 'nullable|string|max:500'
        ]);

        $results = [
            'approved' => [],
            'errors' => []
        ];

        foreach ($validated['sale_ids'] as $saleId) {
            try {
                $sale = Sale::findOrFail($saleId);

                if ($sale->status !== 'pending') {
                    $results['errors'][] = [
                        'sale_id' => $saleId,
                        'error' => 'Sale is not in pending status'
                    ];
                    continue;
                }

                $approval = $sale->approve(auth()->id(), $validated['reason'] ?? 'Bulk approval');
                $results['approved'][] = [
                    'sale_id' => $saleId,
                    'approval_id' => $approval->id
                ];

            } catch (\Exception $e) {
                $results['errors'][] = [
                    'sale_id' => $saleId,
                    'error' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Bulk approval completed',
            'data' => [
                'approved_count' => count($results['approved']),
                'error_count' => count($results['errors']),
                'results' => $results
            ]
        ], count($results['errors']) > 0 ? 207 : 200);
    }

    public function bulkReject(Request $request): JsonResponse
    {
        if (!$this->canManage($request)) {
            return $this->managementRequired();
        }

        $validated = $request->validate([
            'sale_ids' => 'required|array|min:1|max:50',
            'sale_ids.*' => 'required|uuid|exists:sales,id',
            'reason' => 'required|string|max:500'
        ]);

        $results = [
            'rejected' => [],
            'errors' => []
        ];

        foreach ($validated['sale_ids'] as $saleId) {
            try {
                $sale = Sale::findOrFail($saleId);

                if ($sale->status !== 'pending') {
                    $results['errors'][] = [
                        'sale_id' => $saleId,
                        'error' => 'Sale is not in pending status'
                    ];
                    continue;
                }

                $approval = $sale->reject(auth()->id(), $validated['reason']);
                $results['rejected'][] = [
                    'sale_id' => $saleId,
                    'approval_id' => $approval->id
                ];

            } catch (\Exception $e) {
                $results['errors'][] = [
                    'sale_id' => $saleId,
                    'error' => $e->getMessage()
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Bulk rejection completed',
            'data' => [
                'rejected_count' => count($results['rejected']),
                'error_count' => count($results['errors']),
                'results' => $results
            ]
        ], count($results['errors']) > 0 ? 207 : 200);
    }

    public function getApprovalQueue(Request $request): JsonResponse
    {
        $query = Sale::pending()
            ->with([
                'createdBy:id,name',
                'buyer:id,name,type',
                'deductions:id,sale_id,deduction_type,amount'
            ])
            ->orderBy('created_at', 'asc');

        // Apply filters to approval queue
        if ($request->has('min_amount')) {
            $query->where('net_income', '>=', $request->min_amount);
        }

        if ($request->has('max_amount')) {
            $query->where('net_income', '<=', $request->max_amount);
        }

        if ($request->has('created_by')) {
            $query->where('created_by', $request->created_by);
        }

        if ($request->has('sale_type')) {
            $query->where('sale_type', $request->sale_type);
        }

        // Pagination
        $perPage = min($request->get('per_page', 20), 100);
        $pendingSales = $query->paginate($perPage);

        // Calculate queue statistics
        $queueStats = [
            'total_pending' => Sale::pending()->count(),
            'total_value' => Sale::pending()->sum('net_income'),
            'oldest_pending' => Sale::pending()->orderBy('created_at')->first()?->created_at,
            'avg_processing_time' => SalesApproval::recent(30)
                ->join('sales', 'sales_approvals.sale_id', '=', 'sales.id')
                ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, sales.created_at, sales_approvals.action_taken_at)) as avg_hours')
                ->first()
                ->avg_hours ?? 0,
            'by_sale_type' => Sale::pending()
                ->selectRaw('sale_type, COUNT(*) as count, SUM(net_income) as total_value')
                ->groupBy('sale_type')
                ->get()
                ->mapWithKeys(function($item) {
                    return [$item->sale_type => [
                        'count' => $item->count,
                        'total_value' => $item->total_value
                    ]];
                })->toArray()
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'pending_sales' => $pendingSales,
                'queue_statistics' => $queueStats
            ]
        ]);
    }

    private function canManage(Request $request): bool
    {
        $farmId = $request->header('X-Tenant-ID');
        $role = $farmId ? $request->user()?->getRoleOnFarm($farmId) : null;

        return in_array($role, ['owner', 'manager'], true);
    }

    private function managementRequired(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'message' => 'Only farm owners and managers can manage sale approvals',
        ], 403);
    }

    public function getApprovalHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'nullable|uuid|exists:users,id',
            'action' => ['nullable', Rule::in(['approved', 'rejected'])],
            'days' => 'nullable|integer|min:1|max:365'
        ]);

        $query = SalesApproval::with([
            'sale:id,buyer_name,net_income,sale_date',
            'approvedBy:id,name'
        ]);

        if (isset($validated['user_id'])) {
            $query->byApprover($validated['user_id']);
        }

        if (isset($validated['action'])) {
            $query->byAction($validated['action']);
        }

        $query->recent($validated['days'] ?? 30);
        $query->orderBy('action_taken_at', 'desc');

        // Pagination
        $perPage = min($request->get('per_page', 20), 100);
        $history = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $history,
            'meta' => [
                'filters' => $validated,
                'period_days' => $validated['days'] ?? 30
            ]
        ]);
    }
}
