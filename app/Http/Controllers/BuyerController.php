<?php

namespace App\Http\Controllers;

use App\Models\Buyer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class BuyerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Buyer::query();

        // Apply filters
        if ($request->has('status')) {
            if ($request->status === 'active') {
                $query->active();
            } else {
                $query->where('is_active', $request->status === 'true');
            }
        }

        if ($request->has('type')) {
            $query->byType($request->type);
        }

        if ($request->has('location')) {
            $query->byLocation($request->location);
        }

        if ($request->has('reliable')) {
            $minScore = $request->reliable ? 4.0 : 0;
            $query->reliable($minScore);
        }

        if ($request->has('regular')) {
            $minTransactions = $request->regular ? 5 : 0;
            $query->regular($minTransactions);
        }

        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Sorting
        $sortField = $request->get('sort', 'name');
        $sortDirection = $request->get('direction', 'asc');
        
        $allowedSorts = ['name', 'type', 'location', 'total_purchases', 'total_transactions', 'reliability_score', 'created_at'];
        if (in_array($sortField, $allowedSorts)) {
            $query->orderBy($sortField, $sortDirection);
        }

        // Include relationships
        $with = ['createdBy:id,name'];
        if ($request->has('include_sales')) {
            $with[] = 'sales:id,buyer_id,net_income,sale_date,status';
        }
        $query->with($with);

        // Pagination
        $perPage = min($request->get('per_page', 15), 100);
        $buyers = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $buyers,
            'meta' => [
                'total_active' => Buyer::active()->count(),
                'total_inactive' => Buyer::where('is_active', false)->count(),
                'average_reliability' => Buyer::active()->avg('reliability_score')
            ]
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->canManage($request)) {
            return $this->managementRequired();
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['individual', 'aggregator', 'broker', 'neighbour', 'supermarket', 'restaurant', 'hotel', 'wholesaler', 'processor', 'export_company', 'other'])],
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'location' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'credit_limit' => 'nullable|numeric|min:0|max:99999999.99',
            'payment_terms' => ['nullable', Rule::in(['immediate', 'weekly', 'monthly', 'custom'])],
            'payment_days' => 'nullable|integer|min:0|max:365',
            'contact_info' => 'nullable|array'
        ]);

        $validated['created_by'] = auth()->id();

        $buyer = Buyer::create($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Buyer created successfully',
            'data' => $buyer->load('createdBy:id,name')
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $buyer = Buyer::with(['createdBy:id,name'])->findOrFail($id);

        // Get purchase analysis
        $analysis = $buyer->getPurchaseAnalysis();

        return response()->json([
            'status' => 'success',
            'data' => [
                'buyer' => $buyer,
                'analysis' => $analysis
            ]
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if (!$this->canManage($request)) {
            return $this->managementRequired();
        }

        $buyer = Buyer::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'type' => ['sometimes', 'required', Rule::in(['individual', 'aggregator', 'broker', 'neighbour', 'supermarket', 'restaurant', 'hotel', 'wholesaler', 'processor', 'export_company', 'other'])],
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'location' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:1000',
            'credit_limit' => 'nullable|numeric|min:0|max:99999999.99',
            'payment_terms' => ['nullable', Rule::in(['immediate', 'weekly', 'monthly', 'custom'])],
            'payment_days' => 'nullable|integer|min:0|max:365',
            'contact_info' => 'nullable|array',
            'is_active' => 'sometimes|boolean'
        ]);

        $buyer->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Buyer updated successfully',
            'data' => $buyer->load('createdBy:id,name')
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (!$this->canManage($request)) {
            return $this->managementRequired();
        }

        $buyer = Buyer::findOrFail($id);
        
        // Check if buyer has any sales
        if ($buyer->sales()->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cannot delete buyer with existing sales records. Consider deactivating instead.'
            ], 400);
        }

        $buyer->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Buyer deleted successfully'
        ]);
    }

    public function updateReliabilityScore(string $id): JsonResponse
    {
        $buyer = Buyer::findOrFail($id);
        $buyer->updateReliabilityScore();

        return response()->json([
            'status' => 'success',
            'message' => 'Reliability score updated successfully',
            'data' => [
                'new_score' => $buyer->reliability_score
            ]
        ]);
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $buyer = Buyer::findOrFail($id);

        $validated = $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        $buyer->deactivate($validated['reason']);

        return response()->json([
            'status' => 'success',
            'message' => 'Buyer deactivated successfully'
        ]);
    }

    public function reactivate(string $id): JsonResponse
    {
        $buyer = Buyer::findOrFail($id);
        $buyer->reactivate();

        return response()->json([
            'status' => 'success',
            'message' => 'Buyer reactivated successfully'
        ]);
    }

    public function getTopBuyers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'criteria' => ['sometimes', Rule::in(['value', 'frequency', 'reliability', 'average_order'])],
            'limit' => 'sometimes|integer|min:1|max:50'
        ]);

        $criteria = $validated['criteria'] ?? 'value';
        $limit = $validated['limit'] ?? 10;

        $topBuyers = Buyer::getTopBuyers($criteria, $limit);

        return response()->json([
            'status' => 'success',
            'data' => $topBuyers,
            'meta' => [
                'criteria' => $criteria,
                'limit' => $limit
            ]
        ]);
    }

    public function checkCreditLimit(Request $request, string $id): JsonResponse
    {
        $buyer = Buyer::findOrFail($id);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0'
        ]);

        $canPurchase = $buyer->canPurchase($validated['amount']);
        $outstandingCredit = $buyer->getOutstandingCredit();
        $availableCredit = $buyer->credit_limit - $outstandingCredit;

        return response()->json([
            'status' => 'success',
            'data' => [
                'can_purchase' => $canPurchase,
                'requested_amount' => $validated['amount'],
                'outstanding_credit' => $outstandingCredit,
                'credit_limit' => $buyer->credit_limit,
                'available_credit' => max(0, $availableCredit),
                'payment_terms' => $buyer->payment_terms
            ]
        ]);
    }

    public function getBuyerStats(): JsonResponse
    {
        $stats = [
            'total_buyers' => Buyer::count(),
            'active_buyers' => Buyer::active()->count(),
            'inactive_buyers' => Buyer::where('is_active', false)->count(),
            'by_type' => Buyer::selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
            'reliability_distribution' => [
                'excellent' => Buyer::reliable(4.5)->count(),
                'good' => Buyer::whereBetween('reliability_score', [3.5, 4.49])->count(),
                'fair' => Buyer::whereBetween('reliability_score', [2.5, 3.49])->count(),
                'poor' => Buyer::where('reliability_score', '<', 2.5)->count()
            ],
            'regular_buyers' => Buyer::regular()->count(),
            'average_reliability' => round(Buyer::active()->avg('reliability_score'), 2),
            'total_credit_limit' => Buyer::active()->sum('credit_limit'),
            'total_outstanding' => Buyer::active()->get()->sum(function($buyer) {
                return $buyer->getOutstandingCredit();
            })
        ];

        return response()->json([
            'status' => 'success',
            'data' => $stats
        ]);
    }

    public function export(Request $request)
    {
        if (!$this->canManage($request)) {
            return $this->managementRequired();
        }

        $validated = $request->validate([
            'format' => 'nullable|in:csv',
            'search' => 'nullable|string|max:255',
            'type' => 'nullable|string|max:50',
            'status' => 'nullable|in:active,inactive',
        ]);
        $query = Buyer::query()->orderBy('name');
        if (!empty($validated['search'])) {
            $query->search($validated['search']);
        }
        if (!empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }
        if (!empty($validated['status'])) {
            $query->where('is_active', $validated['status'] === 'active');
        }

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Name', 'Type', 'Phone', 'Email', 'Location', 'Status', 'Transactions', 'Total Purchases', 'Outstanding Balance']);
        foreach ($query->get() as $buyer) {
            fputcsv($stream, [
                $buyer->name,
                $buyer->type,
                $buyer->phone,
                $buyer->email,
                $buyer->location,
                $buyer->is_active ? 'active' : 'inactive',
                $buyer->total_transactions,
                $buyer->total_purchases,
                $buyer->getOutstandingCredit(),
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response("\xEF\xBB\xBF" . $csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="buyers-' . now()->format('Y-m-d-His') . '.csv"',
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
            'message' => 'Only farm owners and managers can manage buyers',
        ], 403);
    }
}
