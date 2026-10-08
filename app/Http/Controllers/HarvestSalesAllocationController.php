<?php

namespace App\Http\Controllers;

use App\Models\HarvestSalesAllocation;
use App\Models\Harvest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class HarvestSalesAllocationController extends Controller
{
    /**
     * Get all allocations for a harvest
     */
    public function index(string $harvestId): JsonResponse
    {
        $harvest = Harvest::find($harvestId);

        if (!$harvest) {
            return response()->json([
                'success' => false,
                'message' => 'Harvest not found'
            ], 404);
        }

        $allocations = HarvestSalesAllocation::forHarvest($harvestId)
            ->with('createdBy')
            ->orderBy('allocation_date', 'desc')
            ->get();

        $summary = HarvestSalesAllocation::getAllocationSummary($harvestId);

        return response()->json([
            'success' => true,
            'data' => [
                'harvest_id' => $harvestId,
                'allocations' => $allocations,
                'summary' => $summary,
                'remaining_quantity' => $harvest->getRemainingQuantity()
            ]
        ]);
    }

    /**
     * Create new allocation
     */
    public function store(Request $request, string $harvestId): JsonResponse
    {
        $harvest = Harvest::find($harvestId);

        if (!$harvest) {
            return response()->json([
                'success' => false,
                'message' => 'Harvest not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'allocation_type' => 'required|in:sale,storage,personal_use,loss',
            'allocated_quantity' => 'required|numeric|min:0.001',
            'unit' => 'nullable|string|max:50',
            'destination' => 'nullable|string|max:255',
            'price_per_unit' => 'nullable|numeric|min:0',
            'allocation_date' => 'nullable|date',
            'status' => 'nullable|in:pending,confirmed,delivered',
            'notes' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check if there's enough quantity available
            $remainingQuantity = $harvest->getRemainingQuantity();
            
            if ($request->allocated_quantity > $remainingQuantity) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient quantity available',
                    'data' => [
                        'remaining_quantity' => $remainingQuantity,
                        'requested_quantity' => $request->allocated_quantity,
                        'excess' => $request->allocated_quantity - $remainingQuantity
                    ]
                ], 422);
            }

            $allocation = HarvestSalesAllocation::create([
                'id' => Str::uuid(),
                'harvest_id' => $harvestId,
                'allocation_type' => $request->allocation_type,
                'allocated_quantity' => $request->allocated_quantity,
                'unit' => $request->unit ?? $harvest->unit,
                'destination' => $request->destination,
                'price_per_unit' => $request->price_per_unit,
                'allocation_date' => $request->allocation_date ?? now(),
                'status' => $request->status ?? 'pending',
                'notes' => $request->notes,
                'created_by' => $request->user()->id,
            ]);

            // Calculate total value for sales
            if ($allocation->allocation_type === 'sale' && $allocation->price_per_unit) {
                $allocation->calculateTotalValue();
            }

            $allocation->load('createdBy');

            return response()->json([
                'success' => true,
                'message' => 'Allocation created successfully',
                'data' => $allocation
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create allocation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update allocation
     */
    public function update(Request $request, string $harvestId, string $allocationId): JsonResponse
    {
        $allocation = HarvestSalesAllocation::where('harvest_id', $harvestId)->find($allocationId);

        if (!$allocation) {
            return response()->json([
                'success' => false,
                'message' => 'Allocation not found'
            ], 404);
        }

        // Check if user can edit this allocation
        if (!$this->canEditAllocation($request->user(), $allocation)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to edit this allocation'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'allocation_type' => 'in:sale,storage,personal_use,loss',
            'allocated_quantity' => 'numeric|min:0.001',
            'unit' => 'nullable|string|max:50',
            'destination' => 'nullable|string|max:255',
            'price_per_unit' => 'nullable|numeric|min:0',
            'allocation_date' => 'date',
            'status' => 'in:pending,confirmed,delivered',
            'notes' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // If quantity is being changed, validate against available quantity
            if ($request->has('allocated_quantity')) {
                $validation = $allocation->validateAllocation();
                
                if (!$validation['valid']) {
                    return response()->json([
                        'success' => false,
                        'message' => $validation['error'],
                        'data' => $validation
                    ], 422);
                }
            }

            $allocation->update($request->only([
                'allocation_type',
                'allocated_quantity',
                'unit',
                'destination',
                'price_per_unit',
                'allocation_date',
                'status',
                'notes'
            ]));

            // Recalculate total value if price or quantity changed
            if ($request->has('price_per_unit') || $request->has('allocated_quantity')) {
                $allocation->calculateTotalValue();
            }

            $allocation->load('createdBy');

            return response()->json([
                'success' => true,
                'message' => 'Allocation updated successfully',
                'data' => $allocation
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update allocation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete allocation
     */
    public function destroy(Request $request, string $harvestId, string $allocationId): JsonResponse
    {
        $allocation = HarvestSalesAllocation::where('harvest_id', $harvestId)->find($allocationId);

        if (!$allocation) {
            return response()->json([
                'success' => false,
                'message' => 'Allocation not found'
            ], 404);
        }

        // Check if user can delete this allocation
        if (!$this->canDeleteAllocation($request->user(), $allocation)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete this allocation'
            ], 403);
        }

        try {
            $allocation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Allocation deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete allocation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm allocation
     */
    public function confirm(Request $request, string $harvestId, string $allocationId): JsonResponse
    {
        $allocation = HarvestSalesAllocation::where('harvest_id', $harvestId)->find($allocationId);

        if (!$allocation) {
            return response()->json([
                'success' => false,
                'message' => 'Allocation not found'
            ], 404);
        }

        if (!$this->canConfirmAllocation($request->user(), $allocation)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to confirm allocations'
            ], 403);
        }

        if ($allocation->confirm()) {
            return response()->json([
                'success' => true,
                'message' => 'Allocation confirmed successfully',
                'data' => $allocation
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Allocation cannot be confirmed (may already be confirmed or delivered)'
        ], 400);
    }

    /**
     * Mark allocation as delivered
     */
    public function markDelivered(Request $request, string $harvestId, string $allocationId): JsonResponse
    {
        $allocation = HarvestSalesAllocation::where('harvest_id', $harvestId)->find($allocationId);

        if (!$allocation) {
            return response()->json([
                'success' => false,
                'message' => 'Allocation not found'
            ], 404);
        }

        if (!$this->canMarkDelivered($request->user(), $allocation)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to mark deliveries'
            ], 403);
        }

        if ($allocation->markDelivered()) {
            return response()->json([
                'success' => true,
                'message' => 'Allocation marked as delivered successfully',
                'data' => $allocation
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Allocation cannot be marked as delivered (must be confirmed first)'
        ], 400);
    }

    /**
     * Get sales analytics
     */
    public function salesAnalytics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $analytics = HarvestSalesAllocation::getSalesAnalytics(
                $request->start_date,
                $request->end_date
            );

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate sales analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get allocation summary across all harvests
     */
    public function allocationsSummary(Request $request): JsonResponse
    {
        $query = HarvestSalesAllocation::query();

        if ($request->has('allocation_type')) {
            $query->byType($request->allocation_type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->inDateRange($request->start_date, $request->end_date);
        }

        $allocations = $query->with(['harvest.cropCycle', 'createdBy'])
            ->orderBy('allocation_date', 'desc')
            ->paginate($request->get('per_page', 15));

        // Summary statistics
        $summary = [
            'total_allocations' => $allocations->total(),
            'total_quantity_allocated' => $query->sum('allocated_quantity'),
            'total_sales_value' => $query->sales()->sum('total_value'),
            'pending_value' => $query->sales()->pending()->sum('total_value'),
            'delivered_value' => $query->sales()->delivered()->sum('total_value'),
            'allocation_types' => [
                'sale' => $query->byType('sale')->count(),
                'storage' => $query->byType('storage')->count(),
                'personal_use' => $query->byType('personal_use')->count(),
                'loss' => $query->byType('loss')->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'allocations' => $allocations,
                'summary' => $summary
            ]
        ]);
    }

    // Helper Methods

    /**
     * Check if user can edit allocation
     */
    protected function canEditAllocation($user, HarvestSalesAllocation $allocation): bool
    {
        $role = $user->getRoleOnFarm($allocation->harvest->cropCycle->farm_id);
        
        // Owners and managers can edit any allocation
        if (in_array($role, ['owner', 'manager'])) {
            return true;
        }

        // Users can edit their own pending allocations
        return $allocation->created_by === $user->id && $allocation->status === 'pending';
    }

    /**
     * Check if user can delete allocation
     */
    protected function canDeleteAllocation($user, HarvestSalesAllocation $allocation): bool
    {
        $role = $user->getRoleOnFarm($allocation->harvest->cropCycle->farm_id);
        
        // Only owners can delete allocations
        if ($role === 'owner') {
            return true;
        }

        // Managers can delete pending allocations
        return $role === 'manager' && $allocation->status === 'pending';
    }

    /**
     * Check if user can confirm allocation
     */
    protected function canConfirmAllocation($user, HarvestSalesAllocation $allocation): bool
    {
        $role = $user->getRoleOnFarm($allocation->harvest->cropCycle->farm_id);
        
        return in_array($role, ['owner', 'manager']);
    }

    /**
     * Check if user can mark as delivered
     */
    protected function canMarkDelivered($user, HarvestSalesAllocation $allocation): bool
    {
        $role = $user->getRoleOnFarm($allocation->harvest->cropCycle->farm_id);
        
        return in_array($role, ['owner', 'manager']);
    }
}
