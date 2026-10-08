<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InventoryItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            $farmId = $this->farmId($request);
            if (!$farmId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active farm selected'
                ], 400);
            }

            $category = $request->query('category');
            $lowStock = $request->query('low_stock');
            $expiring = $request->query('expiring');
            $search = $request->query('search');

            $query = InventoryItem::where('farm_id', $farmId)
                                   ->with(['creator']);

            if ($category) {
                $query->byCategory($category);
            }

            if ($lowStock === 'true') {
                $query->lowStock();
            }

            if ($expiring === 'true') {
                $query->expiringSoon();
            }

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('supplier', 'LIKE', "%{$search}%")
                      ->orWhere('description', 'LIKE', "%{$search}%");
                });
            }

            $items = $query->orderBy('name', 'asc')->paginate(50);

            $items->getCollection()->transform(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'category' => $item->category,
                    'unit' => $item->unit,
                    'costPerUnit' => $item->cost_per_unit,
                    'cost_per_unit' => $item->cost_per_unit,
                    'quantity' => $item->current_quantity,
                    'current_quantity' => $item->current_quantity,
                    'reorderLevel' => $item->min_quantity,
                    'min_quantity' => $item->min_quantity,
                    'total_value' => $item->total_value,
                    'total_quantity_in' => $item->total_quantity_in,
                    'total_quantity_out' => $item->total_quantity_out,
                    'movement_count' => $item->movement_count,
                    'expiryDate' => $item->expiry_date,
                    'expiry_date' => $item->expiry_date,
                    'supplier' => $item->supplier,
                    'location' => $item->storage_location,
                    'storage_location' => $item->storage_location,
                    'batchNumber' => $item->batch_number,
                    'batch_number' => $item->batch_number,
                    'description' => $item->description,
                    'status' => $item->status,
                    'is_low_stock' => $item->is_low_stock,
                    'is_out_of_stock' => $item->is_out_of_stock,
                    'is_expiring_soon' => $item->is_expiring_soon,
                    'is_expired' => $item->is_expired,
                    'days_to_expiry' => $item->days_to_expiry,
                    'inventory_turnover_rate' => $item->inventory_turnover_rate,
                    'average_cost' => $item->average_cost,
                    'last_movement_date' => $item->last_movement_date,
                    'created_at' => $item->created_at,
                    'createdAt' => $item->created_at,
                    'updatedAt' => $item->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $items
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve inventory items',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:200',
                'category' => 'required|string|in:seeds,fertilizers,pesticides,equipment,tools,supplies,fuel,other',
                'unit' => 'required|string|max:50',
                'cost_per_unit' => 'nullable|numeric|min:0|max:1000000',
                'quantity' => 'nullable|numeric|min:0|max:999999',
                'min_quantity' => 'nullable|numeric|min:0|max:999999',
                'expiry_date' => 'nullable|date|after:today',
                'supplier' => 'nullable|string|max:200',
                'storage_location' => 'nullable|string|max:200',
                'batch_number' => 'nullable|string|max:100',
                'description' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $itemData = $validator->validated();
            $user = $request->user();
            $farmId = $this->farmId($request);
            if (!$user || !$farmId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User or active farm not found'
                ], 401);
            }
            
            $openingQuantity = (float) ($itemData['quantity'] ?? 0);
            $itemData['farm_id'] = $farmId;
            $itemData['created_by'] = $user->id;
            $itemData['quantity'] = 0;
            $itemData['current_quantity'] = 0;
            $itemData['min_quantity'] = $itemData['min_quantity'] ?? 0;

            $item = DB::transaction(function () use ($itemData, $openingQuantity) {
                $item = InventoryItem::create($itemData);
                if ($openingQuantity > 0) {
                    $item->stockIn($openingQuantity, $item->cost_per_unit, [
                        'movement_date' => now()->toDateString(),
                        'batch_number' => $item->batch_number,
                        'notes' => 'Initial stock',
                    ]);
                } else {
                    $item->checkAndCreateAlerts();
                }

                return $item->fresh();
            });

            return response()->json([
                'success' => true,
                'message' => 'Inventory item created successfully',
                'data' => [
                    'item' => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'category' => $item->category,
                        'unit' => $item->unit,
                        'costPerUnit' => $item->cost_per_unit,
                        'cost_per_unit' => $item->cost_per_unit,
                        'quantity' => $item->current_quantity,
                        'current_quantity' => $item->current_quantity,
                        'reorderLevel' => $item->min_quantity,
                        'min_quantity' => $item->min_quantity,
                        'total_value' => $item->total_value,
                        'total_quantity_in' => $item->total_quantity_in,
                        'total_quantity_out' => $item->total_quantity_out,
                        'movement_count' => $item->movement_count,
                        'expiryDate' => $item->expiry_date,
                        'expiry_date' => $item->expiry_date,
                        'supplier' => $item->supplier,
                        'location' => $item->storage_location,
                        'storage_location' => $item->storage_location,
                        'batchNumber' => $item->batch_number,
                        'batch_number' => $item->batch_number,
                        'description' => $item->description,
                        'status' => $item->status,
                        'is_low_stock' => $item->is_low_stock,
                        'is_out_of_stock' => $item->is_out_of_stock,
                        'is_expiring_soon' => $item->is_expiring_soon,
                        'is_expired' => $item->is_expired,
                        'days_to_expiry' => $item->days_to_expiry,
                        'inventory_turnover_rate' => $item->inventory_turnover_rate,
                        'average_cost' => $item->average_cost,
                        'last_movement_date' => $item->last_movement_date,
                        'created_at' => $item->created_at,
                        'createdAt' => $item->created_at,
                        'updatedAt' => $item->updated_at,
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Item creation failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function show(Request $request, string $itemId): JsonResponse
    {
        try {
            $user = $request->user();
            $farmId = $this->farmId($request);
            if (!$user || !$farmId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User or active farm not found'
                ], 401);
            }

            $item = InventoryItem::where('farm_id', $farmId)
                ->with([
                    'creator',
                    'stockMovements' => function($query) {
                        $query->orderBy('movement_date', 'desc')->limit(20);
                    },
                    'stockMovements.cropCycle',
                    'stockMovements.worker',
                    'alerts' => function($query) {
                        $query->active()->orderBy('alert_date', 'desc');
                    }
                ])->findOrFail($itemId);

            return response()->json([
                'success' => true,
                'data' => [
                    'item' => $item,
                    'usage_by_crop' => $item->stockMovements()
                                          ->where('movement_type', 'out')
                                          ->whereNotNull('crop_cycle_id')
                                          ->with('cropCycle')
                                          ->selectRaw('crop_cycle_id, SUM(quantity) as total_usage')
                                          ->groupBy('crop_cycle_id')
                                          ->get(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve item details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function update(Request $request, string $itemId): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $user = $request->user();
            $farmId = $this->farmId($request);
            if (!$user || !$farmId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User or active farm not found'
                ], 401);
            }

            $item = InventoryItem::where('farm_id', $farmId)
                                  ->findOrFail($itemId);

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:200',
                'category' => 'sometimes|string|in:seeds,fertilizers,pesticides,equipment,tools,supplies,fuel,other',
                'unit' => 'sometimes|string|max:50',
                'cost_per_unit' => 'sometimes|numeric|min:0|max:1000000',
                'min_quantity' => 'sometimes|numeric|min:0|max:999999',
                'expiry_date' => 'sometimes|nullable|date',
                'supplier' => 'sometimes|nullable|string|max:200',
                'storage_location' => 'sometimes|nullable|string|max:200',
                'batch_number' => 'sometimes|nullable|string|max:100',
                'description' => 'sometimes|nullable|string|max:1000',
                'status' => 'sometimes|string|in:active,inactive,expired,discontinued',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $item->update($validator->validated());
            $item->checkAndCreateAlerts();

            return response()->json([
                'success' => true,
                'message' => 'Item updated successfully',
                'data' => [
                    'item' => $item->load('creator')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Item update failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function destroy(Request $request, string $itemId): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $item = InventoryItem::where('farm_id', $this->farmId($request))
                                  ->findOrFail($itemId);

            if ($item->stockMovements()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete item with existing stock history'
                ], 403);
            }

            $item->delete();

            return response()->json([
                'success' => true,
                'message' => 'Item deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Item deletion failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function stockIn(Request $request, string $itemId): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $validator = Validator::make($request->all(), [
                'quantity' => 'required|numeric|min:0.001|max:999999',
                'cost_per_unit' => 'nullable|numeric|min:0|max:1000000',
                'batch_number' => 'nullable|string|max:100',
                'movement_date' => 'nullable|date|before_or_equal:today',
                'notes' => 'nullable|string|max:1000',
                'create_expense' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            $data['movement_date'] = $data['movement_date'] ?? now()->toDateString();

            [$item, $movement] = DB::transaction(function () use ($request, $itemId, $data) {
                $item = InventoryItem::where('farm_id', $this->farmId($request))
                    ->lockForUpdate()
                    ->findOrFail($itemId);
                $movement = $item->stockIn(
                    $data['quantity'],
                    $data['cost_per_unit'] ?? null,
                    $data
                );

                return [$item->fresh(), $movement];
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock added successfully',
                'data' => [
                    'movement' => $movement->load(['inventoryItem', 'creator']),
                    'item' => $item
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 400);
        }
    }

    public function stockOut(Request $request, string $itemId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'quantity' => 'required|numeric|min:0.001|max:999999',
                'movement_date' => 'nullable|date|before_or_equal:today',
                'notes' => 'nullable|string|max:1000',
                'crop_cycle_id' => 'nullable|uuid|exists:crop_cycles,id',
                'bed_id' => 'nullable|uuid|exists:beds,id',
                'labour_task_id' => 'nullable|uuid|exists:crop_tasks,id',
                'worker_id' => 'nullable|uuid|exists:workers,id',
                'override_expiry' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            $data['movement_date'] = $data['movement_date'] ?? now()->toDateString();

            if (($data['override_expiry'] ?? false) && !$this->canManage($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only farm owners and managers can override expiry restrictions',
                ], 403);
            }

            [$item, $movement] = DB::transaction(function () use ($request, $itemId, $data) {
                $item = InventoryItem::where('farm_id', $this->farmId($request))
                    ->lockForUpdate()
                    ->findOrFail($itemId);
                $movement = $item->stockOut($data['quantity'], $data);

                return [$item->fresh(), $movement];
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock used successfully',
                'data' => [
                    'movement' => $movement->load(['inventoryItem', 'cropCycle', 'worker', 'creator']),
                    'item' => $item
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 400);
        }
    }

    public function adjustStock(Request $request, string $itemId): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $validator = Validator::make($request->all(), [
                'quantity' => 'required|numeric|min:-999999|max:999999',
                'reason' => 'required|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            [$item, $movement] = DB::transaction(function () use ($request, $itemId) {
                $item = InventoryItem::where('farm_id', $this->farmId($request))
                    ->lockForUpdate()
                    ->findOrFail($itemId);
                $movement = $item->adjustStock($request->quantity, $request->reason);

                return [$item->fresh(), $movement];
            });

            return response()->json([
                'success' => true,
                'message' => 'Stock adjusted successfully',
                'data' => [
                    'movement' => $movement->load(['inventoryItem', 'creator']),
                    'item' => $item
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 400);
        }
    }

    public function markExpired(Request $request, string $itemId): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $validator = Validator::make($request->all(), [
                'quantity' => 'nullable|numeric|min:0.001|max:999999',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            [$item, $movement] = DB::transaction(function () use ($request, $itemId) {
                $item = InventoryItem::where('farm_id', $this->farmId($request))
                    ->lockForUpdate()
                    ->findOrFail($itemId);
                $movement = $item->markExpired($request->quantity);

                return [$item->fresh(), $movement];
            });

            return response()->json([
                'success' => true,
                'message' => 'Expired stock removed successfully',
                'data' => [
                    'movement' => $movement->load(['inventoryItem', 'creator']),
                    'item' => $item
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 400);
        }
    }

    public function dashboard(Request $request): JsonResponse
    {
        try {
            $totalValue = InventoryItem::getTotalInventoryValue();
            $lowStockItems = InventoryItem::getLowStockItems();
            $expiringSoonItems = InventoryItem::getExpiringSoonItems();
            $categoryStats = InventoryItem::getCategoryStats();
            $fastMovingItems = InventoryItem::getFastMovingItems(5);

            return response()->json([
                'success' => true,
                'data' => [
                    'total_inventory_value' => $totalValue,
                    'low_stock_count' => $lowStockItems->count(),
                    'expiring_soon_count' => $expiringSoonItems->count(),
                    'out_of_stock_count' => InventoryItem::outOfStock()->count(),
                    'category_stats' => $categoryStats,
                    'low_stock_items' => $lowStockItems->take(10),
                    'expiring_soon_items' => $expiringSoonItems->take(10),
                    'fast_moving_items' => $fastMovingItems,
                    'alerts_summary' => \App\Models\InventoryAlert::getActiveSummary(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load inventory dashboard',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function analytics(Request $request): JsonResponse
    {
        try {
            $period = $request->query('period', '30_days');
            $days = $period === 'year' ? 365 : 30;
            $startDate = now()->subDays($days)->toDateString();
            $endDate = now()->toDateString();

            $movementSummary = \App\Models\StockMovement::getMovementSummary($startDate, $endDate);
            $dailyMovements = \App\Models\StockMovement::getDailyMovements($days);
            $topInItems = \App\Models\StockMovement::getTopItems('in', 10);
            $topOutItems = \App\Models\StockMovement::getTopItems('out', 10);
            $mostUsedItems = InventoryItem::getMostUsedItems(10);

            return response()->json([
                'success' => true,
                'data' => [
                    'movement_summary' => $movementSummary,
                    'daily_movements' => $dailyMovements,
                    'top_purchases' => $topInItems,
                    'top_usage' => $topOutItems,
                    'most_used_items' => $mostUsedItems,
                    'period' => $period,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate inventory analytics',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function movements(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $farmId = $this->farmId($request);
            if (!$user || !$farmId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User or active farm not found'
                ], 401);
            }

            $itemId = $request->query('itemId');
            $limit = $request->query('limit', 50);

            $query = \App\Models\StockMovement::where('farm_id', $farmId)
                ->with(['inventoryItem']);

            if ($itemId) {
                $query->where('inventory_item_id', $itemId);
            }

            $movements = $query->orderBy('movement_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate($limit);

            return response()->json([
                'success' => true,
                'data' => $movements
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve movements',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function search(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $farmId = $this->farmId($request);
            if (!$user || !$farmId) {
                return response()->json([
                    'success' => false,
                    'message' => 'User or active farm not found'
                ], 401);
            }

            $query = $request->query('q', '');
            $includeArchived = $request->query('includeArchived', false);
            $limit = $request->query('limit', 20);

            $itemsQuery = InventoryItem::where('farm_id', $farmId)
                ->where(function($q) use ($query) {
                    $q->where('name', 'LIKE', "%{$query}%")
                      ->orWhere('supplier', 'LIKE', "%{$query}%")
                      ->orWhere('description', 'LIKE', "%{$query}%")
                      ->orWhere('category', 'LIKE', "%{$query}%");
                });

            if (!$includeArchived) {
                $itemsQuery->where('status', '!=', 'discontinued');
            }

            $items = $itemsQuery->orderBy('name', 'asc')->take($limit)->get();

            return response()->json([
                'success' => true,
                'data' => ['items' => $items]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to search items',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function export(Request $request)
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $validator = Validator::make($request->query(), [
                'format' => 'nullable|in:csv,pdf',
                'category' => 'nullable|string',
                'status' => 'nullable|in:active,inactive,expired,discontinued',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid inventory export filters',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $format = $request->query('format', 'csv');
            $query = InventoryItem::where('farm_id', $this->farmId($request))->orderBy('name');
            if ($request->query('category')) {
                $query->where('category', $request->query('category'));
            }
            if ($request->query('status')) {
                $query->where('status', $request->query('status'));
            }
            $items = $query->get();
            $filename = 'inventory-' . now()->format('Y-m-d-His') . '.' . $format;

            if ($format === 'pdf') {
                $options = new Options();
                $options->set('isRemoteEnabled', false);
                $pdf = new Dompdf($options);
                $pdf->loadHtml(view('exports.inventory', [
                    'items' => $items,
                    'generatedAt' => now(),
                    'totalValue' => (float) $items->sum('total_value'),
                ])->render());
                $pdf->setPaper('a4', 'landscape');
                $pdf->render();

                return response($pdf->output(), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]);
            }

            return response($this->buildCsv($items), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export data',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $validator = Validator::make($request->all(), [
                'itemIds' => 'required|array|min:1',
                'itemIds.*' => 'required|string|exists:inventory_items,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $itemIds = $request->itemIds;
            $eligible = InventoryItem::where('farm_id', $this->farmId($request))
                ->whereIn('id', $itemIds)
                ->whereDoesntHave('stockMovements');
            $deletedCount = (clone $eligible)->count();

            if ($deletedCount !== count($itemIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Items with stock history cannot be deleted',
                ], 403);
            }

            $eligible->delete();

            return response()->json([
                'success' => true,
                'message' => "Successfully deleted {$deletedCount} items",
                'data' => ['deleted_count' => $deletedCount]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete items',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $validator = Validator::make($request->all(), [
                'itemIds' => 'required|array|min:1',
                'itemIds.*' => 'required|string|exists:inventory_items,id',
                'updates' => 'required|array',
                'updates.category' => 'sometimes|string|max:50',
                'updates.supplier' => 'sometimes|string|max:100',
                'updates.status' => 'sometimes|in:active,inactive,expired,discontinued'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $itemIds = $request->itemIds;
            $updates = $request->updates;
            
            $updatedCount = InventoryItem::where('farm_id', $this->farmId($request))
                ->whereIn('id', $itemIds)
                ->update($updates);

            return response()->json([
                'success' => true,
                'message' => "Successfully updated {$updatedCount} items",
                'data' => ['updated_count' => $updatedCount]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update items',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function farmId(Request $request): ?string
    {
        return $request->header('X-Tenant-ID') ?? $request->input('farm_id');
    }

    private function canManage(Request $request): bool
    {
        $farmId = $this->farmId($request);
        $role = $farmId ? $request->user()?->getRoleOnFarm($farmId) : null;

        return in_array($role, ['owner', 'manager'], true);
    }

    private function managementRequired(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Only farm owners and managers can perform this inventory action',
        ], 403);
    }

    private function buildCsv($items): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'Name', 'Category', 'Current Quantity', 'Unit', 'Cost Per Unit',
            'Total Value', 'Minimum Quantity', 'Supplier', 'Expiry Date', 'Status',
        ]);

        foreach ($items as $item) {
            fputcsv($stream, [
                $item->name,
                $item->category,
                $item->current_quantity,
                $item->unit,
                $item->cost_per_unit,
                $item->total_value,
                $item->min_quantity,
                $item->supplier,
                optional($item->expiry_date)->format('Y-m-d'),
                $item->status,
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }
}
