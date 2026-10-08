<?php

namespace App\Http\Controllers;

use App\Models\LabourEntry;
use App\Models\Worker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class LabourEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $status = $request->query('status');
            $workerId = $request->query('worker_id');
            $cropCycleId = $request->query('crop_cycle_id');
            $labourType = $request->query('labour_type');
            $paymentStatus = $request->query('payment_status');
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            $query = LabourEntry::with(['worker', 'cropCycle', 'bed', 'creator', 'approver']);

            if ($status) {
                if ($status === 'approved') {
                    $query->approved();
                } elseif ($status === 'pending') {
                    $query->pending();
                } elseif ($status === 'rejected') {
                    $query->rejected();
                }
            }

            if ($workerId) {
                $query->byWorker($workerId);
            }

            if ($cropCycleId) {
                $query->byCropCycle($cropCycleId);
            }

            if ($labourType) {
                $query->byLabourType($labourType);
            }

            if ($paymentStatus) {
                if ($paymentStatus === 'paid') {
                    $query->paid();
                } elseif ($paymentStatus === 'unpaid') {
                    $query->unpaid();
                }
            }

            if ($startDate && $endDate) {
                $query->byDateRange($startDate, $endDate);
            }

            $entries = $query->orderBy('labour_date', 'desc')->paginate(50);

            return response()->json([
                'success' => true,
                'data' => $entries
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve labour entries',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'worker_id' => 'nullable|uuid|exists:workers,id',
                'worker_name' => 'required|string|max:100',
                'labour_date' => 'required|date|before_or_equal:today',
                'labour_type' => 'required|string|in:weeding,bed_preparation,transplanting,watering,spraying,fertilizer_application,harvest_labour,general_work,fence_repair,misc',
                'payment_type' => 'required|string|in:daily,piece_rate,group_labour,multi_day',
                'amount' => 'required|numeric|min:0|max:1000000',
                'units' => 'nullable|integer|min:1|max:1000',
                'number_of_workers' => 'nullable|integer|min:1|max:100',
                'crop_cycle_id' => 'nullable|uuid|exists:crop_cycles,id',
                'bed_id' => 'nullable|uuid|exists:beds,id',
                'description' => 'nullable|string|max:1000',
                'payment_method' => 'nullable|string|in:cash,mpesa,bank_transfer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            $entryData = $validator->validated();

            if ($request->worker_id) {
                $worker = Worker::findOrFail($request->worker_id);
                $entryData['worker_name'] = $worker->name;
            }

            if ($request->payment_type === 'piece_rate' && !$request->units) {
                return response()->json([
                    'success' => false,
                    'message' => 'Units are required for piece rate payment type'
                ], 422);
            }

            if ($request->payment_type === 'group_labour' && (!$request->number_of_workers || $request->number_of_workers <= 1)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Number of workers must be greater than 1 for group labour'
                ], 422);
            }

            $entryData['created_by'] = $user->id;
            $entryData['number_of_workers'] = $entryData['number_of_workers'] ?? 1;

            $entryData['status'] = 'pending';
            $labourEntry = LabourEntry::create($entryData);

            if ($this->canApproveLabour($user, $request)) {
                $labourEntry->approve($user->id, 'Recorded by farm management');
            }
            $labourEntry->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Labour entry created successfully',
                'data' => [
                    'labour_entry' => $labourEntry->load(['worker', 'cropCycle', 'bed', 'creator', 'approver'])
                ]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Labour entry creation failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function show(Request $request, string $entryId): JsonResponse
    {
        try {
            $entry = LabourEntry::with([
                'worker', 
                'cropCycle', 
                'bed', 
                'creator', 
                'approver',
                'approvals.approver'
            ])->findOrFail($entryId);

            return response()->json([
                'success' => true,
                'data' => ['labour_entry' => $entry]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve labour entry',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function update(Request $request, string $entryId): JsonResponse
    {
        try {
            $entry = LabourEntry::findOrFail($entryId);

            if (!$this->canManageEntry($request, $entry)) {
                return $this->forbidden();
            }

            if ($entry->status === 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Approved labour entries cannot be modified'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'worker_id' => 'sometimes|nullable|uuid|exists:workers,id',
                'worker_name' => 'sometimes|string|max:100',
                'labour_date' => 'sometimes|date|before_or_equal:today',
                'labour_type' => 'sometimes|string|in:weeding,bed_preparation,transplanting,watering,spraying,fertilizer_application,harvest_labour,general_work,fence_repair,misc',
                'payment_type' => 'sometimes|string|in:daily,piece_rate,group_labour,multi_day',
                'amount' => 'sometimes|numeric|min:0|max:1000000',
                'units' => 'sometimes|nullable|integer|min:1|max:1000',
                'number_of_workers' => 'sometimes|nullable|integer|min:1|max:100',
                'crop_cycle_id' => 'sometimes|nullable|uuid|exists:crop_cycles,id',
                'bed_id' => 'sometimes|nullable|uuid|exists:beds,id',
                'description' => 'sometimes|nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updateData = $validator->validated();

            if (isset($updateData['worker_id']) && $updateData['worker_id']) {
                $worker = Worker::findOrFail($updateData['worker_id']);
                $updateData['worker_name'] = $worker->name;
            }

            $entry->update($updateData);

            return response()->json([
                'success' => true,
                'message' => 'Labour entry updated successfully',
                'data' => [
                    'labour_entry' => $entry->load(['worker', 'cropCycle', 'bed', 'creator', 'approver'])
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Labour entry update failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function destroy(Request $request, string $entryId): JsonResponse
    {
        try {
            $entry = LabourEntry::findOrFail($entryId);

            if (!$this->canManageEntry($request, $entry)) {
                return $this->forbidden();
            }

            if ($entry->status === 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Approved labour entries cannot be deleted'
                ], 403);
            }

            $entry->delete();

            return response()->json([
                'success' => true,
                'message' => 'Labour entry deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Labour entry deletion failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function approve(Request $request, string $entryId): JsonResponse
    {
        try {
            if (!$this->canApproveLabour($request->user(), $request)) {
                return $this->forbidden();
            }

            $validator = Validator::make($request->all(), [
                'reason' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $entry = LabourEntry::findOrFail($entryId);
            $entry->approve($request->user()->id, $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Labour entry approved successfully',
                'data' => [
                    'labour_entry' => $entry->load(['worker', 'cropCycle', 'bed', 'creator', 'approver'])
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

    public function reject(Request $request, string $entryId): JsonResponse
    {
        try {
            if (!$this->canApproveLabour($request->user(), $request)) {
                return $this->forbidden();
            }

            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $entry = LabourEntry::findOrFail($entryId);
            $entry->reject($request->user()->id, $request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Labour entry rejected successfully',
                'data' => [
                    'labour_entry' => $entry->load(['worker', 'cropCycle', 'bed', 'creator', 'approver'])
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

    public function markPaid(Request $request, string $entryId): JsonResponse
    {
        try {
            if (!$this->canApproveLabour($request->user(), $request)) {
                return $this->forbidden();
            }

            $validator = Validator::make($request->all(), [
                'payment_method' => 'required|string|in:cash,mpesa,bank_transfer',
                'payment_date' => 'nullable|date|before_or_equal:today',
                'paid_amount' => 'required|numeric|min:0.01',
                'payment_reference' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $entry = LabourEntry::findOrFail($entryId);

            $entry->recordPayment(
                $request->paid_amount,
                $request->payment_method,
                $request->payment_date,
                $request->payment_reference
            );
            $message = $entry->payment_status === 'paid'
                ? 'Labour entry marked as paid'
                : 'Partial labour payment recorded';

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'labour_entry' => $entry->load(['worker', 'cropCycle', 'bed', 'creator', 'approver'])
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

    public function bulkStore(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'entries' => 'required|array|min:1|max:50',
                'entries.*.worker_id' => 'nullable|uuid|exists:workers,id',
                'entries.*.worker_name' => 'required|string|max:100',
                'entries.*.labour_date' => 'required|date|before_or_equal:today',
                'entries.*.labour_type' => 'required|string|in:weeding,bed_preparation,transplanting,watering,spraying,fertilizer_application,harvest_labour,general_work,fence_repair,misc',
                'entries.*.payment_type' => 'required|string|in:daily,piece_rate,group_labour,multi_day',
                'entries.*.amount' => 'required|numeric|min:0|max:1000000',
                'entries.*.units' => 'nullable|integer|min:1|max:1000',
                'entries.*.number_of_workers' => 'nullable|integer|min:1|max:100',
                'entries.*.crop_cycle_id' => 'nullable|uuid|exists:crop_cycles,id',
                'entries.*.bed_id' => 'nullable|uuid|exists:beds,id',
                'entries.*.description' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            $createdEntries = [];
            $errors = [];

            DB::beginTransaction();

            foreach ($request->entries as $index => $entryData) {
                try {
                    if (isset($entryData['worker_id']) && $entryData['worker_id']) {
                        $worker = Worker::find($entryData['worker_id']);
                        if ($worker) {
                            $entryData['worker_name'] = $worker->name;
                        }
                    }

                    $entryData['created_by'] = $user->id;
                    $entryData['number_of_workers'] = $entryData['number_of_workers'] ?? 1;

                    $entryData['status'] = 'pending';
                    $labourEntry = LabourEntry::create($entryData);
                    if ($this->canApproveLabour($user, $request)) {
                        $labourEntry->approve($user->id, 'Recorded by farm management');
                    }
                    $labourEntry->refresh();
                    $createdEntries[] = $labourEntry;

                } catch (\Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'error' => $e->getMessage()
                    ];
                }
            }

            if (!empty($errors)) {
                DB::rollback();
                return response()->json([
                    'success' => false,
                    'message' => 'Bulk creation failed',
                    'errors' => $errors
                ], 422);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($createdEntries) . ' labour entries created successfully',
                'data' => [
                    'labour_entries' => $createdEntries,
                    'count' => count($createdEntries)
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Bulk labour entry creation failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function canApproveLabour($user, Request $request): bool
    {
        $farmId = $request->header('X-Tenant-ID') ?? $request->input('farm_id');
        if (!$farmId) {
            return false;
        }

        $role = $user->getRoleOnFarm($farmId);

        return in_array($role, ['owner', 'manager'], true)
            || $user->hasPermissionOnFarm($farmId, 'manage_labour');
    }

    private function canManageEntry(Request $request, LabourEntry $entry): bool
    {
        return $this->canApproveLabour($request->user(), $request)
            || ($entry->status === 'pending' && $entry->created_by === $request->user()->id);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'You do not have permission to manage this labour record',
        ], 403);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $startDate = $request->query('start_date', now()->subDays(30)->toDateString());
            $endDate = $request->query('end_date', now()->toDateString());

            $summary = LabourEntry::getLabourSummary($startDate, $endDate);

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
                'message' => 'Failed to generate labour summary',
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

            $summary = LabourEntry::getLabourSummary($startDate, $endDate);
            $mostExpensiveTypes = LabourEntry::getMostExpensiveLabourTypes();

            $dailyCosts = LabourEntry::approved()
                               ->selectRaw('labour_date, SUM(amount) as daily_cost')
                               ->where('labour_date', '>=', $startDate)
                               ->groupBy('labour_date')
                               ->orderBy('labour_date')
                               ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'most_expensive_types' => $mostExpensiveTypes,
                    'daily_costs' => $dailyCosts,
                    'period' => $period,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate labour analytics',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
