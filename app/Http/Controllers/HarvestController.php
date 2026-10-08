<?php

namespace App\Http\Controllers;

use App\Models\Harvest;
use App\Models\CropCycle;
use App\Models\Worker;
use App\Models\Bed;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HarvestController extends Controller
{
    /**
     * Get all harvests with filtering
     */
    public function index(Request $request): JsonResponse
    {
        $farmId = $this->activeFarmId($request);
        $query = $this->farmHarvests($farmId)
            ->with(['cropCycle', 'worker', 'harvestBeds', 'salesAllocations']);

        // Apply filters
        if ($request->has('crop_cycle_id')) {
            $query->forCrop($request->crop_cycle_id);
        }

        if ($request->has('worker_id')) {
            $query->byWorker($request->worker_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('grade')) {
            $query->byGrade($request->grade);
        }

        if ($request->has('start_date') && $request->has('end_date')) {
            $query->inDateRange($request->start_date, $request->end_date);
        }

        // Sorting
        $sortField = in_array($request->get('sort'), ['harvest_date', 'total_quantity', 'status', 'created_at'], true)
            ? $request->get('sort')
            : 'harvest_date';
        $sortDirection = $request->get('direction') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDirection);

        $harvests = $query->paginate($request->get('per_page', 15));
        $harvests->getCollection()->each(function (Harvest $harvest) {
            $remaining = $harvest->getRemainingQuantity();
            $harvest->setAttribute('remaining_quantity', $remaining);
            $harvest->setAttribute('quantity_remaining', $remaining);
            $harvest->setAttribute('yield_performance_score', $harvest->getYieldPerformanceScore());
        });

        return response()->json([
            'success' => true,
            'data' => $harvests,
        ]);
    }

    /**
     * Create new harvest entry
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'crop_cycle_id' => 'required|uuid|exists:crop_cycles,id',
            'harvest_date' => 'required|date',
            'total_quantity' => 'required|numeric|min:0.001',
            'unit' => 'required|string|max:50',
            'grade_type' => 'required|in:simple,detailed',
            'simple_grade' => 'nullable|required_if:grade_type,simple|in:marketable,non_marketable',
            'detailed_grade' => 'nullable|required_if:grade_type,detailed|in:A,B,C,reject',
            'worker_id' => 'nullable|uuid|exists:workers,id',
            'notes' => 'nullable|string|max:1000',
            'photos' => 'nullable|array',
            'photos.*' => 'string|url',
            'expected_quantity' => 'nullable|numeric|min:0',
            'weather_conditions' => 'nullable|array',
            'moisture_content' => 'nullable|numeric|between:0,100',
            'is_final_harvest' => 'boolean',
            'beds' => 'nullable|array',
            'beds.*.bed_id' => 'nullable|uuid|exists:beds,id',
            'beds.*.bed_name' => 'required_without:beds.*.bed_id|nullable|string|max:100',
            'beds.*.quantity' => 'required|numeric|min:0.001',
            'beds.*.unit' => 'nullable|string|max:50',
            'beds.*.grade' => 'nullable|string|max:50',
            'beds.*.area_harvested' => 'nullable|numeric|min:0',
            'beds.*.bed_notes' => 'nullable|string|max:500',
            'harvest_notes' => 'nullable|array',
            'harvest_notes.*.note' => 'required|string|max:1000',
            'harvest_notes.*.note_type' => 'required|in:general,health_condition,pest_issue,weather_impact,quality_issue,anomaly,improvement',
            'harvest_notes.*.is_critical' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Check if crop cycle exists and is active
            $farmId = $this->activeFarmId($request);
            $cropCycle = CropCycle::where('farm_id', $farmId)->find($request->crop_cycle_id);
            if (!$cropCycle) {
                return response()->json([
                    'success' => false,
                    'message' => 'Crop cycle not found'
                ], 404);
            }

            // Validate bed total matches total quantity if beds provided
            if (count($request->input('beds', [])) > 0) {
                $bedTotal = collect($request->beds)->sum('quantity');
                if (abs($bedTotal - $request->total_quantity) > 0.01) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bed quantities total does not match total quantity'
                    ], 422);
                }
            }

            // Determine status based on user role
            $status = $this->determineHarvestStatus($request->user(), $cropCycle->farm_id);

            $expectedQuantity = $request->filled('expected_quantity')
                ? $request->expected_quantity
                : ($cropCycle->expected_yield ?: $cropCycle->expected_yield_kg ?: null);

            $harvest = DB::transaction(function () use ($request, $cropCycle, $status, $expectedQuantity) {
                $harvest = Harvest::create([
                'id' => Str::uuid(),
                'crop_cycle_id' => $request->crop_cycle_id,
                'harvest_date' => $request->harvest_date,
                'total_quantity' => $request->total_quantity,
                'unit' => $request->unit,
                'grade_type' => $request->grade_type,
                'simple_grade' => $request->simple_grade,
                'detailed_grade' => $request->detailed_grade,
                'worker_id' => $request->worker_id,
                'notes' => $request->notes,
                'photos' => $request->photos,
                'status' => $status,
                'created_by' => $request->user()->id,
                'expected_quantity' => $expectedQuantity,
                'weather_conditions' => $request->weather_conditions,
                'moisture_content' => $request->moisture_content,
                'is_final_harvest' => $request->is_final_harvest ?? false,
                ]);

            // Calculate variance if expected quantity provided
                if ($expectedQuantity !== null) {
                    $harvest->calculateVariance();
                }

            // Add bed data if provided
                if (count($request->input('beds', [])) > 0) {
                    foreach ($request->beds as $bedData) {
                    if (!empty($bedData['bed_id'])) {
                        $bed = Bed::where('farm_id', $cropCycle->farm_id)->find($bedData['bed_id']);
                        if (!$bed) {
                            throw \Illuminate\Validation\ValidationException::withMessages([
                                'beds' => ['A selected bed does not belong to the active farm.'],
                            ]);
                        }
                        $bedData['bed_name'] = $bed->name;
                    }
                        $harvest->addBedHarvest($bedData);
                    }
                }

            // Add harvest notes if provided
                if ($request->has('harvest_notes')) {
                    foreach ($request->harvest_notes as $noteData) {
                        $noteData['created_by'] = $request->user()->id;
                        $harvest->addNote($noteData);
                    }
                }

            // Auto-approve if user has permission
                if ($status === 'approved') {
                    $harvest->updateCropCycleTotals();
                
                    if ($harvest->is_final_harvest) {
                        $harvest->closeCropCycleIfFinal();
                    }
                }

                return $harvest;
            });

            $harvest->load(['cropCycle', 'worker', 'harvestBeds', 'harvestNotes', 'createdBy']);

            return response()->json([
                'success' => true,
                'message' => 'Harvest recorded successfully',
                'data' => $harvest
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create harvest entry',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get harvest details
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $harvest = Harvest::with([
            'cropCycle',
            'worker',
            'harvestBeds',
            'harvestNotes.createdBy',
            'salesAllocations',
            'createdBy',
            'approvedBy'
        ])->whereHas('cropCycle', fn ($query) => $query->where('farm_id', $this->activeFarmId($request)))->find($id);

        if (!$harvest) {
            return response()->json([
                'success' => false,
                'message' => 'Harvest not found'
            ], 404);
        }

        // Add calculated fields
        $harvestData = $harvest->toArray();
        $harvestData['remaining_quantity'] = $harvest->getRemainingQuantity();
        $harvestData['total_allocated_quantity'] = $harvest->getTotalAllocatedQuantity();
        $harvestData['yield_performance_score'] = $harvest->getYieldPerformanceScore();
        $harvestData['is_overdue'] = $harvest->isOverdue();

        return response()->json([
            'success' => true,
            'data' => $harvestData
        ]);
    }

    /**
     * Update harvest entry
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $harvest = $this->farmHarvests($this->activeFarmId($request))->find($id);

        if (!$harvest) {
            return response()->json([
                'success' => false,
                'message' => 'Harvest not found'
            ], 404);
        }

        // Check if user can edit this harvest
        if (!$this->canEditHarvest($request->user(), $harvest)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to edit this harvest'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'harvest_date' => 'date',
            'total_quantity' => 'numeric|min:0.001',
            'unit' => 'string|max:50',
            'grade_type' => 'in:simple,detailed',
            'simple_grade' => 'nullable|required_if:grade_type,simple|in:marketable,non_marketable',
            'detailed_grade' => 'nullable|required_if:grade_type,detailed|in:A,B,C,reject',
            'worker_id' => 'nullable|uuid|exists:workers,id',
            'notes' => 'nullable|string|max:1000',
            'photos' => 'nullable|array',
            'photos.*' => 'string|url',
            'expected_quantity' => 'nullable|numeric|min:0',
            'weather_conditions' => 'nullable|array',
            'moisture_content' => 'nullable|numeric|between:0,100',
            'is_final_harvest' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $wasApproved = $harvest->status === 'approved';
            $harvest->update($request->only([
                'harvest_date',
                'total_quantity',
                'unit',
                'grade_type',
                'simple_grade',
                'detailed_grade',
                'worker_id',
                'notes',
                'photos',
                'expected_quantity',
                'weather_conditions',
                'moisture_content',
                'is_final_harvest'
            ]));

            // Recalculate variance if expected quantity changed
            if ($request->has('expected_quantity')) {
                $harvest->calculateVariance();
            }

            // Update crop cycle totals if approved
            if ($harvest->status === 'approved') {
                $harvest->updateCropCycleTotals();
            }

            if ($wasApproved && $harvest->is_final_harvest) {
                $harvest->closeCropCycleIfFinal();
            }

            $harvest->load(['cropCycle', 'worker', 'harvestBeds', 'harvestNotes']);

            return response()->json([
                'success' => true,
                'message' => 'Harvest updated successfully',
                'data' => $harvest
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update harvest',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete harvest entry
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $harvest = $this->farmHarvests($this->activeFarmId($request))->find($id);

        if (!$harvest) {
            return response()->json([
                'success' => false,
                'message' => 'Harvest not found'
            ], 404);
        }

        // Check if user can delete this harvest
        if (!$this->canDeleteHarvest($request->user(), $harvest)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to delete this harvest'
            ], 403);
        }

        try {
            DB::transaction(function () use ($harvest) {
                $cropCycle = $harvest->cropCycle;
                $wasApproved = $harvest->status === 'approved';
                $harvest->delete();
                if ($wasApproved && $cropCycle) {
                    $cropCycle->harvests()->first()?->updateCropCycleTotals();
                    if (!$cropCycle->harvests()->approved()->exists()) {
                        $cropCycle->update(['actual_yield' => 0, 'actual_yield_kg' => 0]);
                    }
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Harvest deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete harvest',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve harvest entry
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $farmId = $this->activeFarmId($request);
        if (!$this->farmHarvests($farmId)->whereKey($id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Harvest not found'
            ], 404);
        }

        if (!in_array($request->user()->getRoleOnFarm($farmId), ['owner', 'manager'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to approve harvests'
            ], 403);
        }

        $harvest = DB::transaction(function () use ($farmId, $id, $request) {
            $locked = $this->farmHarvests($farmId)->lockForUpdate()->find($id);
            return $locked && $locked->approve($request->user()->id) ? $locked : null;
        });

        if ($harvest) {
            return response()->json([
                'success' => true,
                'message' => 'Harvest approved successfully',
                'data' => $harvest->load(['cropCycle', 'worker', 'approvedBy'])
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Harvest cannot be approved (may already be approved/rejected)'
        ], 400);
    }

    /**
     * Reject harvest entry
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $farmId = $this->activeFarmId($request);
        if (!$this->farmHarvests($farmId)->whereKey($id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Harvest not found'
            ], 404);
        }

        if (!in_array($request->user()->getRoleOnFarm($farmId), ['owner', 'manager'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to reject harvests'
            ], 403);
        }

        $harvest = DB::transaction(function () use ($farmId, $id, $request) {
            $locked = $this->farmHarvests($farmId)->lockForUpdate()->find($id);
            return $locked && $locked->reject($request->user()->id, $request->reason) ? $locked : null;
        });

        if ($harvest) {
            return response()->json([
                'success' => true,
                'message' => 'Harvest rejected successfully',
                'data' => $harvest->load(['cropCycle', 'worker', 'approvedBy'])
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Harvest cannot be rejected (may already be approved/rejected)'
        ], 400);
    }

    /**
     * Get harvest timeline for a crop cycle
     */
    public function timeline(Request $request, string $cropCycleId): JsonResponse
    {
        $farmId = $this->activeFarmId($request);
        abort_unless(CropCycle::where('farm_id', $farmId)->whereKey($cropCycleId)->exists(), 404, 'Crop cycle not found.');
        $harvests = $this->farmHarvests($farmId)->approved()
            ->forCrop($cropCycleId)
            ->with(['worker', 'harvestBeds'])
            ->orderBy('harvest_date', 'asc')
            ->get();

        $timeline = $harvests->map(function ($harvest) {
            return [
                'id' => $harvest->id,
                'date' => $harvest->harvest_date->format('Y-m-d'),
                'quantity' => $harvest->total_quantity,
                'unit' => $harvest->unit,
                'grade' => $harvest->getGradeValue(),
                'worker' => $harvest->worker?->name,
                'bed_count' => $harvest->harvestBeds->count(),
                'beds' => $harvest->harvestBeds->pluck('bed_name')->toArray(),
                'variance_percentage' => $harvest->variance_percentage,
                'yield_performance' => $harvest->getYieldPerformanceScore(),
                'notes' => $harvest->notes,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'crop_cycle_id' => $cropCycleId,
                'total_harvests' => $timeline->count(),
                'total_quantity' => $timeline->sum('quantity'),
                'timeline' => $timeline
            ]
        ]);
    }

    /**
     * Get harvest analytics
     */
    public function analytics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'crop_cycle_id' => 'nullable|uuid|exists:crop_cycles,id',
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
            if ($request->crop_cycle_id) {
                abort_unless(
                    CropCycle::where('farm_id', $this->activeFarmId($request))
                        ->whereKey($request->crop_cycle_id)
                        ->exists(),
                    404,
                    'Crop cycle not found.'
                );
                $analytics = Harvest::getHarvestAnalytics(
                    $request->crop_cycle_id,
                    $request->start_date,
                    $request->end_date
                );
            } else {
                // Farm-wide analytics
                $analytics = $this->getFarmWideAnalytics(
                    $this->activeFarmId($request),
                    $request->start_date,
                    $request->end_date
                );
            }

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate analytics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get pending approvals
     */
    public function pendingApprovals(Request $request): JsonResponse
    {
        $pending = $this->farmHarvests($this->activeFarmId($request))->pending()
            ->with(['cropCycle', 'worker', 'createdBy'])
            ->orderBy('harvest_date', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'pending_count' => $pending->count(),
                'pending_harvests' => $pending
            ]
        ]);
    }

    /**
     * Return the operational metrics used by the harvest dashboard.
     */
    public function dashboardMetrics(Request $request): JsonResponse
    {
        $farmId = $this->activeFarmId($request);
        $all = $this->farmHarvests($farmId)->get();
        $approved = $all->where('status', 'approved');
        $pending = $all->where('status', 'pending')->sortBy('created_at');
        $marketable = $approved->filter(fn (Harvest $harvest) => in_array($harvest->getGradeValue(), ['marketable', 'A', 'B'], true));
        $totalYield = (float) $approved->sum('total_quantity');

        return response()->json([
            'success' => true,
            'data' => [
                'total_harvests' => $approved->count(),
                'harvests_this_week' => $approved->filter(fn (Harvest $harvest) => $harvest->harvest_date->gte(now()->startOfWeek()))->count(),
                'total_yield' => $totalYield,
                'average_variance' => round((float) $approved->whereNotNull('variance_percentage')->avg('variance_percentage'), 2),
                'pending_approvals' => $pending->count(),
                'oldest_pending_days' => $pending->isEmpty() ? 0 : $pending->first()->created_at->diffInDays(now()),
                'marketable_percentage' => $totalYield > 0
                    ? round(((float) $marketable->sum('total_quantity') / $totalYield) * 100, 2)
                    : 0,
            ],
        ]);
    }

    /**
     * Download the filtered harvest ledger as a real CSV file.
     */
    public function export(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'format' => 'nullable|in:csv',
            'status' => 'nullable|in:pending,approved,rejected',
            'crop_cycle_id' => 'nullable|uuid',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $query = $this->farmHarvests($this->activeFarmId($request))->with(['cropCycle', 'worker']);
        foreach (['status', 'crop_cycle_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        if ($request->filled('start_date')) {
            $query->whereDate('harvest_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('harvest_date', '<=', $request->end_date);
        }
        $harvests = $query->orderByDesc('harvest_date')->get();

        return response()->streamDownload(function () use ($harvests) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Date', 'Crop', 'Variety', 'Quantity', 'Unit', 'Grade', 'Status', 'Worker', 'Expected Quantity', 'Variance Percent', 'Notes']);
            foreach ($harvests as $harvest) {
                fputcsv($stream, [
                    $harvest->harvest_date->format('Y-m-d'),
                    $harvest->cropCycle?->crop_name,
                    $harvest->cropCycle?->variety,
                    $harvest->total_quantity,
                    $harvest->unit,
                    $harvest->getGradeValue(),
                    $harvest->status,
                    $harvest->worker?->name,
                    $harvest->expected_quantity,
                    $harvest->variance_percentage,
                    $harvest->notes,
                ]);
            }
            fclose($stream);
        }, 'harvests-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // Helper Methods

    protected function activeFarmId(Request $request): string
    {
        $farmId = $request->header('X-Tenant-ID');
        abort_if(!$farmId, 400, 'Farm context is required.');
        abort_unless($request->user()?->hasAccessToFarm($farmId), 403, 'You do not have access to this farm.');

        return $farmId;
    }

    protected function farmHarvests(string $farmId)
    {
        return Harvest::query()->whereHas('cropCycle', fn ($query) => $query->where('farm_id', $farmId));
    }

    /**
     * Determine harvest status based on user role
     */
    protected function determineHarvestStatus($user, string $farmId): string
    {
        // Owners and managers can auto-approve
        $role = $user->getRoleOnFarm($farmId);
        
        return in_array($role, ['owner', 'manager']) ? 'approved' : 'pending';
    }

    /**
     * Check if user can edit harvest
     */
    protected function canEditHarvest($user, Harvest $harvest): bool
    {
        $role = $user->getRoleOnFarm($harvest->cropCycle->farm_id);
        
        // Owners and managers can edit any harvest
        if (in_array($role, ['owner', 'manager'])) {
            return true;
        }

        // Workers can only edit their own pending harvests
        return $harvest->created_by === $user->id && $harvest->status === 'pending';
    }

    /**
     * Check if user can delete harvest
     */
    protected function canDeleteHarvest($user, Harvest $harvest): bool
    {
        $role = $user->getRoleOnFarm($harvest->cropCycle->farm_id);
        
        // Only owners can delete harvests
        if ($role === 'owner') {
            return true;
        }

        // Managers can delete pending harvests
        return $role === 'manager' && $harvest->status === 'pending';
    }

    /**
     * Check if user can approve harvests
     */
    protected function canApproveHarvest($user): bool
    {
        $farmId = request()->header('X-Tenant-ID');
        $role = $farmId ? $user->getRoleOnFarm($farmId) : null;
        
        return in_array($role, ['owner', 'manager']);
    }

    /**
     * Get farm-wide harvest analytics
     */
    protected function getFarmWideAnalytics(string $farmId, $startDate = null, $endDate = null): array
    {
        $query = $this->farmHarvests($farmId)->approved();

        if ($startDate && $endDate) {
            $query->inDateRange($startDate, $endDate);
        }

        $harvests = $query->with(['cropCycle', 'harvestBeds', 'salesAllocations'])->get();

        return [
            'total_harvests' => $harvests->count(),
            'total_quantity' => $harvests->sum('total_quantity'),
            'total_crops_harvested' => $harvests->pluck('crop_cycle_id')->unique()->count(),
            'average_yield_per_harvest' => $harvests->avg('total_quantity'),
            'grade_distribution' => [
                'marketable' => $harvests->where(fn($h) => $h->getGradeValue() === 'marketable')->sum('total_quantity'),
                'non_marketable' => $harvests->where(fn($h) => $h->getGradeValue() === 'non_marketable')->sum('total_quantity'),
                'grade_a' => $harvests->where(fn($h) => $h->getGradeValue() === 'A')->sum('total_quantity'),
                'grade_b' => $harvests->where(fn($h) => $h->getGradeValue() === 'B')->sum('total_quantity'),
                'grade_c' => $harvests->where(fn($h) => $h->getGradeValue() === 'C')->sum('total_quantity'),
                'reject' => $harvests->where(fn($h) => $h->getGradeValue() === 'reject')->sum('total_quantity'),
            ],
            'monthly_trends' => $this->getMonthlyHarvestTrends($harvests),
            'top_performing_crops' => $this->getTopPerformingCrops($harvests),
        ];
    }

    /**
     * Get monthly harvest trends
     */
    protected function getMonthlyHarvestTrends($harvests): array
    {
        return $harvests->groupBy(function($harvest) {
            return $harvest->harvest_date->format('Y-m');
        })->map(function($monthHarvests, $month) {
            return [
                'month' => $month,
                'harvest_count' => $monthHarvests->count(),
                'total_quantity' => $monthHarvests->sum('total_quantity'),
                'average_quantity' => $monthHarvests->avg('total_quantity'),
            ];
        })->sortBy('month')->values()->toArray();
    }

    /**
     * Get top performing crops
     */
    protected function getTopPerformingCrops($harvests): array
    {
        return $harvests->groupBy('crop_cycle_id')->map(function($cropHarvests) {
            $cropCycle = $cropHarvests->first()->cropCycle;
            $totalQuantity = $cropHarvests->sum('total_quantity');
            $harvestCount = $cropHarvests->count();
            
            return [
                'crop_name' => $cropCycle->crop_name,
                'variety' => $cropCycle->variety,
                'total_harvested' => $totalQuantity,
                'harvest_sessions' => $harvestCount,
                'average_per_harvest' => $totalQuantity / $harvestCount,
            ];
        })->sortByDesc('total_harvested')->take(5)->values()->toArray();
    }
}
