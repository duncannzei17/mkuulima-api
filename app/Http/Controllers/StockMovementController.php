<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class StockMovementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $itemId = $request->query('item_id');
            $movementType = $request->query('movement_type');
            $cropId = $request->query('crop_id');
            $workerId = $request->query('worker_id');
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');
            $status = $request->query('status', 'confirmed');

            $query = StockMovement::with([
                'inventoryItem',
                'cropCycle',
                'worker',
                'creator'
            ]);

            if ($status) {
                $query->where('status', $status);
            }

            if ($itemId) {
                $query->byItem($itemId);
            }

            if ($movementType) {
                $query->where('movement_type', $movementType);
            }

            if ($cropId) {
                $query->byCrop($cropId);
            }

            if ($workerId) {
                $query->byWorker($workerId);
            }

            if ($startDate && $endDate) {
                $query->byDateRange($startDate, $endDate);
            }

            $movements = $query->orderBy('movement_date', 'desc')
                             ->orderBy('created_at', 'desc')
                             ->paginate(50);

            return response()->json([
                'success' => true,
                'data' => $movements
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve stock movements',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function show(Request $request, string $movementId): JsonResponse
    {
        try {
            $movement = StockMovement::with([
                'inventoryItem',
                'cropCycle',
                'bed',
                'labourTask',
                'worker',
                'expense',
                'creator'
            ])->findOrFail($movementId);

            return response()->json([
                'success' => true,
                'data' => [
                    'movement' => $movement
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve movement details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function cancel(Request $request, string $movementId): JsonResponse
    {
        try {
            $movement = StockMovement::findOrFail($movementId);
            $movement->cancel();

            return response()->json([
                'success' => true,
                'message' => 'Movement cancelled successfully',
                'data' => [
                    'movement' => $movement->fresh(['inventoryItem', 'creator'])
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

    public function summary(Request $request): JsonResponse
    {
        try {
            $startDate = $request->query('start_date', now()->subDays(30)->toDateString());
            $endDate = $request->query('end_date', now()->toDateString());

            $summary = StockMovement::getMovementSummary($startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate movement summary',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function cropUsage(Request $request, string $cropId): JsonResponse
    {
        try {
            $usage = StockMovement::getCropUsage($cropId);

            return response()->json([
                'success' => true,
                'data' => [
                    'crop_usage' => $usage,
                    'total_items' => $usage->count(),
                    'total_quantity' => $usage->sum('total_quantity'),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve crop usage',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function workerUsage(Request $request, string $workerId): JsonResponse
    {
        try {
            $startDate = $request->query('start_date', now()->subDays(30)->toDateString());
            $endDate = $request->query('end_date', now()->toDateString());

            $usage = StockMovement::getWorkerUsage($workerId, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => [
                    'worker_usage' => $usage,
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ],
                    'total_items' => $usage->count(),
                    'total_quantity' => $usage->sum('total_quantity'),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve worker usage',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}