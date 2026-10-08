<?php

namespace App\Http\Controllers;

use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class WorkerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $includeInactive = $request->query('include_inactive', false);
            $role = $request->query('role');
            $withStats = $request->query('with_stats', false);

            $query = Worker::with(['creator']);

            if (!$includeInactive) {
                $query->active();
            }

            if ($role) {
                $query->byRole($role);
            }

            if ($withStats) {
                $query->withCount('labourEntries');
            }

            $workers = $query->orderBy('name', 'asc')->get();

            $workers->transform(function ($worker) {
                return [
                    'id' => $worker->id,
                    'name' => $worker->name,
                    'phone' => $worker->phone,
                    'id_number' => $worker->id_number,
                    'role' => $worker->role,
                    'status' => $worker->status,
                    'default_daily_rate' => $worker->default_daily_rate,
                    'total_labour_cost' => $worker->total_labour_cost,
                    'total_labour_days' => $worker->total_labour_days,
                    'last_labour_date' => $worker->last_labour_date,
                    'unpaid_amount' => $worker->unpaid_amount,
                    'average_daily_rate' => $worker->average_daily_rate,
                    'notes' => $worker->notes,
                    'created_by' => $worker->creator ? [
                        'id' => $worker->creator->id,
                        'name' => $worker->creator->name,
                    ] : null,
                    'created_at' => $worker->created_at,
                    'updated_at' => $worker->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'workers' => $workers,
                    'total' => $workers->count(),
                    'by_role' => $workers->groupBy('role')->map->count(),
                    'active' => $workers->where('status', 'active')->count(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve workers',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            if (!$this->canManageWorkers($request)) {
                return $this->forbidden();
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:100',
                'phone' => 'nullable|string|max:20',
                'id_number' => 'nullable|string|max:50',
                'role' => 'required|string|in:permanent,casual,seasonal,contractor',
                'default_daily_rate' => 'nullable|numeric|min:0|max:100000',
                'notes' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $workerData = $validator->validated();
            $workerData['created_by'] = $request->user()->id;
            $workerData['status'] = 'active';

            $worker = Worker::create($workerData);

            return response()->json([
                'success' => true,
                'message' => 'Worker created successfully',
                'data' => [
                    'worker' => $worker->load('creator')
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Worker creation failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function show(Request $request, string $workerId): JsonResponse
    {
        try {
            $worker = Worker::with(['creator', 'labourEntries' => function($query) {
                $query->approved()->orderBy('labour_date', 'desc')->limit(10);
            }])->findOrFail($workerId);

            $startDate = $request->query('start_date', now()->subDays(30)->toDateString());
            $endDate = $request->query('end_date', now()->toDateString());

            $labourSummary = $worker->getLabourSummary($startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => [
                    'worker' => $worker,
                    'labour_summary' => $labourSummary,
                    'recent_entries' => $worker->labourEntries,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve worker details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function update(Request $request, string $workerId): JsonResponse
    {
        try {
            if (!$this->canManageWorkers($request)) {
                return $this->forbidden();
            }

            $worker = Worker::findOrFail($workerId);

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:100',
                'phone' => 'sometimes|nullable|string|max:20',
                'id_number' => 'sometimes|nullable|string|max:50',
                'role' => 'sometimes|string|in:permanent,casual,seasonal,contractor',
                'status' => 'sometimes|string|in:active,inactive',
                'default_daily_rate' => 'sometimes|nullable|numeric|min:0|max:100000',
                'notes' => 'sometimes|nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $worker->update($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Worker updated successfully',
                'data' => [
                    'worker' => $worker->load('creator')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Worker update failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function destroy(Request $request, string $workerId): JsonResponse
    {
        try {
            if (!$this->canManageWorkers($request)) {
                return $this->forbidden();
            }

            $worker = Worker::findOrFail($workerId);

            if (!$worker->canBeDeleted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Workers with existing labour entries cannot be deleted'
                ], 403);
            }

            $worker->delete();

            return response()->json([
                'success' => true,
                'message' => 'Worker deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Worker deletion failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function activate(Request $request, string $workerId): JsonResponse
    {
        try {
            if (!$this->canManageWorkers($request)) {
                return $this->forbidden();
            }

            $worker = Worker::findOrFail($workerId);
            $worker->activate();

            return response()->json([
                'success' => true,
                'message' => 'Worker activated successfully',
                'data' => ['worker' => $worker]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Worker activation failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function deactivate(Request $request, string $workerId): JsonResponse
    {
        try {
            if (!$this->canManageWorkers($request)) {
                return $this->forbidden();
            }

            $worker = Worker::findOrFail($workerId);
            $worker->deactivate();

            return response()->json([
                'success' => true,
                'message' => 'Worker deactivated successfully',
                'data' => ['worker' => $worker]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Worker deactivation failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function updateRate(Request $request, string $workerId): JsonResponse
    {
        try {
            if (!$this->canManageWorkers($request)) {
                return $this->forbidden();
            }

            $validator = Validator::make($request->all(), [
                'rate' => 'required|numeric|min:0|max:100000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $worker = Worker::findOrFail($workerId);
            $worker->updateDefaultRate($request->rate);

            return response()->json([
                'success' => true,
                'message' => 'Worker rate updated successfully',
                'data' => ['worker' => $worker]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Rate update failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function mostActive(Request $request): JsonResponse
    {
        try {
            $limit = $request->query('limit', 10);
            $workers = Worker::getMostActiveWorkers($limit);

            return response()->json([
                'success' => true,
                'data' => ['workers' => $workers]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve most active workers',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function canManageWorkers(Request $request): bool
    {
        $farmId = $request->header('X-Tenant-ID');
        $user = $request->user();
        $role = $farmId ? $user?->getRoleOnFarm($farmId) : null;

        return in_array($role, ['owner', 'manager'], true)
            || ($farmId && $user?->hasPermissionOnFarm($farmId, 'manage_labour'));
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to manage workers',
        ], 403);
    }
}
