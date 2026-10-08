<?php

namespace App\Http\Controllers;

use App\Models\InventoryAlert;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class InventoryAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            // Check if user is authenticated and has an active farm
            if (!$request->user()) {
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

            $status = $request->query('status');
            $severity = $request->query('severity');
            $alertType = $request->query('alert_type');
            $itemId = $request->query('item_id');

            $query = InventoryAlert::where('farm_id', $farmId)
                                       ->with(['inventoryItem', 'acknowledgedBy']);

            if ($status) {
                $query->where('status', $status);
            }

            if ($severity) {
                $query->bySeverity($severity);
            }

            if ($alertType) {
                $query->byType($alertType);
            }

            if ($itemId) {
                $query->where('inventory_item_id', $itemId);
            }

            $alerts = $query->orderBy('severity', 'desc')
                           ->orderBy('alert_date', 'asc')
                           ->paginate(50);

            return response()->json([
                'success' => true,
                'data' => $alerts
            ]);

        } catch (\Exception $e) {
            \Log::error('InventoryAlert index error: ' . $e->getMessage(), [
                'user_id' => $request->user()?->id,
                'farm_id' => $this->farmId($request),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve inventory alerts',
                'error' => config('app.debug') ? $e->getMessage() : null,
                'debug_info' => config('app.debug') ? [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ] : null
            ], 500);
        }
    }

    public function show(Request $request, string $alertId): JsonResponse
    {
        try {
            $alert = InventoryAlert::where('farm_id', $this->farmId($request))->with([
                'inventoryItem',
                'acknowledgedBy'
            ])->findOrFail($alertId);

            return response()->json([
                'success' => true,
                'data' => [
                    'alert' => $alert
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve alert details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function acknowledge(Request $request, string $alertId): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $alert = InventoryAlert::where('farm_id', $this->farmId($request))->findOrFail($alertId);
            $alert->acknowledge($request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Alert acknowledged successfully',
                'data' => [
                    'alert' => $alert->fresh(['inventoryItem', 'acknowledgedBy'])
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to acknowledge alert',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function resolve(Request $request, string $alertId): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $alert = InventoryAlert::where('farm_id', $this->farmId($request))->findOrFail($alertId);
            $alert->resolve($request->user()->id);

            return response()->json([
                'success' => true,
                'message' => 'Alert resolved successfully',
                'data' => [
                    'alert' => $alert->fresh(['inventoryItem', 'acknowledgedBy'])
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve alert',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function bulkAcknowledge(Request $request): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $validator = Validator::make($request->all(), [
                'alert_ids' => 'required|array|min:1|max:100',
                'alert_ids.*' => 'uuid|exists:inventory_alerts,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $acknowledgedCount = InventoryAlert::where('farm_id', $this->farmId($request))
                ->whereIn('id', $request->alert_ids)
                ->where('status', 'active')
                ->update([
                    'status' => 'acknowledged',
                    'acknowledged_by' => $request->user()->id,
                    'acknowledged_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => "{$acknowledgedCount} alerts acknowledged successfully",
                'data' => [
                    'acknowledged_count' => $acknowledgedCount
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to acknowledge alerts',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function bulkResolve(Request $request): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $validator = Validator::make($request->all(), [
                'alert_ids' => 'required|array|min:1|max:100',
                'alert_ids.*' => 'uuid|exists:inventory_alerts,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $resolvedCount = InventoryAlert::where('farm_id', $this->farmId($request))
                ->whereIn('id', $request->alert_ids)
                ->whereIn('status', ['active', 'acknowledged'])
                ->update([
                    'status' => 'resolved',
                    'resolved_by' => $request->user()->id,
                    'resolved_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => "{$resolvedCount} alerts resolved successfully",
                'data' => [
                    'resolved_count' => $resolvedCount
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve alerts',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function dashboard(Request $request): JsonResponse
    {
        try {
            $dashboardData = InventoryAlert::getAlertsForDashboard();

            return response()->json([
                'success' => true,
                'data' => $dashboardData
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load alerts dashboard',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $summary = InventoryAlert::getActiveSummary();

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $summary
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate alerts summary',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function autoResolve(Request $request): JsonResponse
    {
        try {
            if (!$this->canManage($request)) {
                return $this->managementRequired();
            }

            $resolvedCount = InventoryAlert::autoResolveAlerts();

            return response()->json([
                'success' => true,
                'message' => "Auto-resolved {$resolvedCount} alerts",
                'data' => [
                    'resolved_count' => $resolvedCount
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to auto-resolve alerts',
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
            'message' => 'Only farm owners and managers can manage inventory alerts',
        ], 403);
    }
}
