<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = InventoryItem::where('farm_id', $request->user()->activeFarm->id)
                ->with(['creator', 'stockMovements']);

            // Apply filters
            if ($request->filled('category')) {
                $query->where('category', $request->category);
            }

            if ($request->filled('status')) {
                if ($request->status === 'low_stock') {
                    $query->whereRaw('current_quantity <= min_quantity');
                } elseif ($request->status === 'out_of_stock') {
                    $query->where('current_quantity', 0);
                } elseif ($request->status === 'expiring') {
                    $query->where('expiry_date', '<=', now()->addDays(30))
                          ->where('expiry_date', '>', now());
                } elseif ($request->status === 'expired') {
                    $query->where('expiry_date', '<', now());
                }
                // Note: Other status values are ignored as we don't have a status column
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                      ->orWhere('supplier', 'ILIKE', "%{$search}%");
                });
            }

            $items = $query->orderBy('name')->get();

            // Transform field names to match frontend expectations
            $transformedItems = $items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'category' => $item->category,
                    'quantity' => $item->current_quantity, // Map current_quantity to quantity
                    'unit' => $item->unit,
                    'reorderLevel' => $item->min_quantity, // Map min_quantity to reorderLevel
                    'supplier' => $item->supplier,
                    'expiryDate' => $item->expiry_date,
                    'location' => $item->storage_location, // Map storage_location to location
                    'costPerUnit' => $item->cost_per_unit,
                    'description' => $item->description,
                    'batchNumber' => $item->batch_number,
                    'barcode' => $item->barcode,
                    'sku' => $item->sku,
                    'tags' => $item->tags ? json_decode($item->tags) : [],
                    'attachments' => $item->attachments ? json_decode($item->attachments) : [],
                    'status' => $item->status,
                    'createdAt' => $item->created_at,
                    'updatedAt' => $item->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $transformedItems
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve inventory items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:chemical,fertilizer,seed,seedling,tool,material',
            'unit' => 'required|string|max:50',
            'cost_per_unit' => 'required|numeric|min:0',
            'current_quantity' => 'required|numeric|min:0',
            'min_quantity' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date|after:today',
            'supplier' => 'nullable|string|max:255',
        ]);

        try {
            $item = InventoryItem::create([
                'farm_id' => $request->user()->activeFarm->id,
                'created_by' => $request->user()->id,
                ...$validated
            ]);

            return response()->json([
                'success' => true,
                'data' => $item->load('creator'),
                'message' => 'Inventory item created successfully'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create inventory item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        try {
            $item = InventoryItem::with(['creator', 'stockMovements.creator'])
                ->where('farm_id', auth()->user()->activeFarm->id)
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $item
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Item not found'
            ], 404);
        }
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|in:chemical,fertilizer,seed,seedling,tool,material',
            'unit' => 'sometimes|required|string|max:50',
            'cost_per_unit' => 'sometimes|required|numeric|min:0',
            'min_quantity' => 'nullable|numeric|min:0',
            'expiry_date' => 'nullable|date',
            'supplier' => 'nullable|string|max:255',
        ]);

        try {
            $item = InventoryItem::where('farm_id', auth()->user()->activeFarm->id)
                ->findOrFail($id);

            $item->update($validated);

            return response()->json([
                'success' => true,
                'data' => $item->load('creator'),
                'message' => 'Inventory item updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update inventory item'
            ], 500);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $item = InventoryItem::where('farm_id', auth()->user()->activeFarm->id)
                ->findOrFail($id);

            // Check if item has stock movements
            if ($item->stockMovements()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete item with existing stock history'
                ], 400);
            }

            $item->delete();

            return response()->json([
                'success' => true,
                'message' => 'Inventory item deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete inventory item'
            ], 500);
        }
    }
}