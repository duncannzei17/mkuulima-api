<?php

namespace App\Http\Controllers;

use App\Models\Bed;
use App\Models\BedAnalytics;
use App\Services\BedReportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class BedController extends Controller
{
    public function report(Request $request, BedReportService $reportService): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|uuid',
            'bed_id' => 'nullable|uuid|exists:beds,id',
            'range' => 'nullable|in:6m,1y,2y,all',
            'timeline_limit' => 'nullable|integer|min:50|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $beds = Bed::where('farm_id', $request->farm_id)
            ->when($request->bed_id, fn ($query, $bedId) => $query->where('id', $bedId))
            ->orderBy('layout_order')
            ->get();

        if ($request->bed_id && $beds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Bed not found for the active farm',
            ], 404);
        }

        $range = $request->get('range', '1y');
        $dateFrom = match ($range) {
            '6m' => now()->subMonths(6)->startOfDay(),
            '1y' => now()->subYear()->startOfDay(),
            '2y' => now()->subYears(2)->startOfDay(),
            default => null,
        };

        return response()->json([
            'success' => true,
            'data' => array_merge(
                [
                    'range' => $range,
                    'date_from' => $dateFrom?->toDateString(),
                    'generated_at' => now()->toISOString(),
                ],
                $reportService->build($beds, $dateFrom, (int) $request->get('timeline_limit', 500))
            ),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Bed::with(['currentCropAssignment.cropCycle', 'latestAnalytics'])
            ->where('farm_id', $request->farm_id)
            ->active();

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by bed type
        if ($request->has('bed_type')) {
            $query->where('bed_type', $request->bed_type);
        }

        // Filter by health score range
        if ($request->has('min_health_score')) {
            $query->where('health_score', '>=', $request->min_health_score);
        }
        
        if ($request->has('max_health_score')) {
            $query->where('health_score', '<=', $request->max_health_score);
        }

        // Filter by performance
        if ($request->boolean('high_performing')) {
            $query->where('yield_performance_score', '>=', 80);
        }
        
        if ($request->boolean('needs_attention')) {
            $query->where(function($q) {
                $q->where('health_score', '<', 70)
                  ->orWhere('needs_maintenance', true)
                  ->orWhere('status', 'issues_detected');
            });
        }

        // Filter available beds
        if ($request->boolean('available_only')) {
            $query->available();
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'ILIKE', '%' . $request->search . '%');
        }

        // Sorting
        $sortField = $request->get('sort_by', 'layout_order');
        $sortDirection = $request->get('sort_direction', 'asc');
        
        $allowedSortFields = [
            'layout_order', 'name', 'health_score', 'yield_performance_score',
            'cost_efficiency_score', 'size_area', 'created_at'
        ];
        
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        }

        $beds = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $beds->map(function ($bed) {
                return array_merge($bed->toArray(), [
                    'performance_summary' => $bed->getPerformanceSummary(),
                    'recommendations' => $bed->getRecommendations(),
                    'utilization_rate' => $bed->getUtilizationRate(),
                    'is_available' => $bed->isAvailable(),
                    'current_crop' => $bed->currentCropAssignment ? [
                        'crop_name' => $bed->currentCropAssignment->crop_name,
                        'variety' => $bed->currentCropAssignment->variety,
                        'start_date' => $bed->currentCropAssignment->start_date,
                        'expected_end_date' => $bed->currentCropAssignment->expected_end_date,
                        'days_planted' => $bed->currentCropAssignment->getDurationDays()
                    ] : null
                ]);
            }),
            'pagination' => [
                'current_page' => $beds->currentPage(),
                'last_page' => $beds->lastPage(),
                'per_page' => $beds->perPage(),
                'total' => $beds->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'farm_id' => 'required|uuid|exists:farms,id',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:1000',
            'bed_type' => 'required|in:' . implode(',', array_keys(Bed::BED_TYPES)),
            'size_length' => 'nullable|numeric|min:0.1|max:1000',
            'size_width' => 'nullable|numeric|min:0.1|max:1000',
            'size_area' => 'nullable|numeric|min:0.01|max:100000',
            'area_unit' => 'nullable|in:square_meters,acres,hectares',
            'layout_order' => 'nullable|integer|min:1',
            'layout_row' => 'nullable|integer|min:1|max:100',
            'layout_column' => 'nullable|integer|min:1|max:100',
            'gps_latitude' => 'nullable|numeric|between:-90,90',
            'gps_longitude' => 'nullable|numeric|between:-180,180',
            'soil_type' => 'nullable|in:clay,loam,sandy,silt,mixed',
            'soil_ph' => 'nullable|numeric|between:0,14',
            'drainage_quality' => 'nullable|in:poor,fair,good,excellent',
            'sun_exposure' => 'nullable|in:full_sun,partial_sun,shade,partial_shade',
            'has_irrigation' => 'boolean',
            'irrigation_type' => 'nullable|string|max:50'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Check for duplicate bed names within the farm
            $existingBed = Bed::where('farm_id', $request->farm_id)
                ->where('name', $request->name)
                ->where('is_active', true)
                ->first();

            if ($existingBed) {
                return response()->json([
                    'success' => false,
                    'message' => 'A bed with this name already exists on this farm',
                ], 422);
            }

            $data = $validator->validated();
            $data['created_by'] = Auth::id();
            $data['status'] = 'empty';
            $data['health_score'] = 100;

            $bed = Bed::create($data);

            DB::commit();

            Log::info('Bed created', [
                'bed_id' => $bed->id,
                'farm_id' => $bed->farm_id,
                'name' => $bed->name,
                'created_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bed created successfully',
                'data' => array_merge($bed->toArray(), [
                    'performance_summary' => $bed->getPerformanceSummary(),
                    'is_available' => $bed->isAvailable()
                ]),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create bed', [
                'error' => $e->getMessage(),
                'farm_id' => $request->farm_id,
                'name' => $request->name
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create bed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, Bed $bed): JsonResponse
    {
        $bed->load([
            'notes' => function($q) {
                $q->orderBy('note_date', 'desc')->limit(10);
            },
            'cropAssignments' => function($q) {
                $q->orderBy('start_date', 'desc')->limit(5);
            },
            'currentCropAssignment.cropCycle',
            'analytics' => function($q) {
                $q->orderBy('analysis_date', 'desc')->limit(6);
            }
        ]);

        return response()->json([
            'success' => true,
            'data' => array_merge($bed->toArray(), [
                'performance_summary' => $bed->getPerformanceSummary(),
                'recommendations' => $bed->getRecommendations(),
                'utilization_rate' => $bed->getUtilizationRate(),
                'is_available' => $bed->isAvailable(),
                'recent_analytics' => $bed->analytics->map(function($analytics) {
                    return [
                        'analysis_date' => $analytics->analysis_date,
                        'analysis_period' => $analytics->analysis_period,
                        'overall_performance_score' => $analytics->overall_performance_score,
                        'yield_performance_score' => $analytics->yield_performance_score,
                        'cost_efficiency_score' => $analytics->cost_efficiency_score,
                        'health_score' => $analytics->average_health_score,
                        'performance_category' => $analytics->performance_category
                    ];
                }),
                'productivity_trends' => $this->getProductivityTrends($bed),
                'health_timeline' => $this->getHealthTimeline($bed)
            ]),
        ]);
    }

    public function update(Request $request, Bed $bed): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:1000',
            'bed_type' => 'sometimes|required|in:' . implode(',', array_keys(Bed::BED_TYPES)),
            'size_length' => 'nullable|numeric|min:0.1|max:1000',
            'size_width' => 'nullable|numeric|min:0.1|max:1000',
            'size_area' => 'nullable|numeric|min:0.01|max:100000',
            'area_unit' => 'nullable|in:square_meters,acres,hectares',
            'layout_order' => 'nullable|integer|min:1',
            'layout_row' => 'nullable|integer|min:1|max:100',
            'layout_column' => 'nullable|integer|min:1|max:100',
            'gps_latitude' => 'nullable|numeric|between:-90,90',
            'gps_longitude' => 'nullable|numeric|between:-180,180',
            'soil_type' => 'nullable|in:clay,loam,sandy,silt,mixed',
            'soil_ph' => 'nullable|numeric|between:0,14',
            'drainage_quality' => 'nullable|in:poor,fair,good,excellent',
            'sun_exposure' => 'nullable|in:full_sun,partial_sun,shade,partial_shade',
            'has_irrigation' => 'boolean',
            'irrigation_type' => 'nullable|string|max:50',
            'status' => 'sometimes|in:' . implode(',', array_keys(Bed::STATUSES)),
            'needs_maintenance' => 'boolean',
            'maintenance_notes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Check for duplicate bed names if name is being changed
            if ($request->has('name') && $request->name !== $bed->name) {
                $existingBed = Bed::where('farm_id', $bed->farm_id)
                    ->where('name', $request->name)
                    ->where('id', '!=', $bed->id)
                    ->where('is_active', true)
                    ->first();

                if ($existingBed) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A bed with this name already exists on this farm',
                    ], 422);
                }
            }

            $data = $validator->validated();
            $data['updated_by'] = Auth::id();

            $bed->update($data);

            // Recalculate health score if environmental factors changed
            if ($request->hasAny(['soil_ph', 'drainage_quality', 'has_irrigation'])) {
                $bed->calculateHealthScore();
            }

            Log::info('Bed updated', [
                'bed_id' => $bed->id,
                'updated_fields' => array_keys($data),
                'updated_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bed updated successfully',
                'data' => array_merge($bed->fresh()->toArray(), [
                    'performance_summary' => $bed->getPerformanceSummary(),
                    'recommendations' => $bed->getRecommendations()
                ]),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update bed', [
                'bed_id' => $bed->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update bed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function assignCrop(Request $request, Bed $bed): JsonResponse
    {
        if (!$this->canManageAssignments($request, $bed)) {
            return response()->json([
                'success' => false,
                'message' => 'Only farm owners and managers can assign crops to beds',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'crop_cycle_id' => 'required|uuid|exists:crop_cycles,id',
            'start_date' => 'nullable|date',
            'expected_end_date' => 'nullable|date|after_or_equal:start_date',
            'area_percentage_used' => 'nullable|integer|min:1|max:100',
            'area_used_sqm' => 'nullable|numeric|min:0.01',
            'plant_count' => 'nullable|integer|min:1',
            'plant_density_per_sqm' => 'nullable|numeric|min:0.01',
            'expected_yield_kg' => 'nullable|numeric|min:0',
            'expected_days_to_harvest' => 'nullable|integer|min:1|max:365',
            'season' => 'nullable|in:dry,wet,transition',
            'irrigation_used' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            if (!$bed->isAvailable()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Bed is not available for crop assignment',
                ], 422);
            }

            $assignmentData = $validator->validated();
            $assignment = $bed->assignCrop($request->crop_cycle_id, $assignmentData);

            return response()->json([
                'success' => true,
                'message' => 'Crop assigned to bed successfully',
                'data' => [
                    'assignment' => $assignment,
                    'bed' => array_merge($bed->fresh()->toArray(), [
                        'current_crop' => [
                            'crop_name' => $assignment->crop_name,
                            'start_date' => $assignment->start_date
                        ]
                    ])
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to assign crop to bed', [
                'bed_id' => $bed->id,
                'crop_cycle_id' => $request->crop_cycle_id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to assign crop to bed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function completeCropCycle(Request $request, Bed $bed): JsonResponse
    {
        if (!$this->canManageAssignments($request, $bed)) {
            return response()->json([
                'success' => false,
                'message' => 'Only farm owners and managers can complete bed crop cycles',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'actual_yield_kg' => 'nullable|numeric|min:0',
            'harvest_quality' => 'nullable|in:poor,fair,good,excellent',
            'completion_notes' => 'nullable|string|max:1000',
            'performance_rating' => 'nullable|in:poor,below_average,average,good,excellent'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $completionData = $validator->validated();
            $success = $bed->completeCropCycle($completionData);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'No active crop assignment found for this bed',
                ], 422);
            }

            $completedBed = $bed->fresh();

            return response()->json([
                'success' => true,
                'message' => 'Crop cycle completed successfully',
                'data' => [
                    'bed' => array_merge($completedBed->toArray(), [
                        'performance_summary' => $completedBed->getPerformanceSummary()
                    ])
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to complete crop cycle', [
                'bed_id' => $bed->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to complete crop cycle',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function recalculateHealth(Bed $bed): JsonResponse
    {
        try {
            $newScore = $bed->calculateHealthScore();

            return response()->json([
                'success' => true,
                'message' => 'Health score recalculated successfully',
                'data' => [
                    'bed_id' => $bed->id,
                    'new_health_score' => $newScore,
                    'updated_at' => $bed->health_score_updated_at
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to recalculate bed health', [
                'bed_id' => $bed->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to recalculate health score',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function canManageAssignments(Request $request, Bed $bed): bool
    {
        $farmId = $request->header('X-Tenant-ID') ?? $request->input('farm_id');

        return $farmId === $bed->farm_id
            && in_array($request->user()?->getRoleOnFarm($farmId), ['owner', 'manager'], true);
    }

    public function archive(Request $request, Bed $bed): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $success = $bed->archive($request->reason);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot archive bed with active crop assignment',
                ], 422);
            }

            Log::info('Bed archived', [
                'bed_id' => $bed->id,
                'reason' => $request->reason,
                'archived_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bed archived successfully',
                'data' => $bed->fresh(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to archive bed', [
                'bed_id' => $bed->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to archive bed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function reactivate(Bed $bed): JsonResponse
    {
        try {
            $success = $bed->reactivate();

            if ($success) {
                Log::info('Bed reactivated', [
                    'bed_id' => $bed->id,
                    'reactivated_by' => Auth::id()
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Bed reactivated successfully',
                    'data' => $bed->fresh(),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to reactivate bed',
            ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to reactivate bed', [
                'bed_id' => $bed->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reactivate bed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function analytics(Request $request, Bed $bed): JsonResponse
    {
        $period = $request->get('period', 'monthly');
        $months = $request->get('months', 12);

        try {
            // Get recent analytics
            $analytics = $bed->analytics()
                ->where('analysis_period', $period)
                ->where('analysis_date', '>=', now()->subMonths($months))
                ->orderBy('analysis_date', 'desc')
                ->get();

            // Generate new analytics if none exist for current period
            if ($analytics->isEmpty() || 
                $analytics->first()->analysis_date < now()->startOfMonth()) {
                $newAnalytics = BedAnalytics::generateAnalyticsForBed($bed, $period);
                $analytics->prepend($newAnalytics);
            }

            // Performance summary
            $latest = $analytics->first();
            $performanceSummary = [
                'overall_performance_score' => $latest->overall_performance_score ?? 0,
                'yield_performance' => $latest->yield_performance_score ?? 0,
                'cost_efficiency' => $latest->cost_efficiency_score ?? 0,
                'quality_performance' => $latest->quality_performance_score ?? 0,
                'health_score' => $latest->average_health_score ?? $bed->health_score,
                'performance_category' => $latest->performance_category ?? 'average',
                'farm_ranking' => $latest->farm_ranking,
                'risk_level' => $latest->risk_level ?? 'low'
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'bed_info' => [
                        'id' => $bed->id,
                        'name' => $bed->name,
                        'bed_type' => $bed->bed_type,
                        'size_area' => $bed->size_area,
                        'status' => $bed->status
                    ],
                    'performance_summary' => $performanceSummary,
                    'analytics_history' => $analytics->take(12),
                    'trends' => $this->calculateTrends($analytics),
                    'recommendations' => $latest->recommendations ?? $bed->getRecommendations(),
                    'comparative_analysis' => $this->getComparativeAnalysis($bed),
                    'productivity_metrics' => $this->getProductivityMetrics($bed)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get bed analytics', [
                'bed_id' => $bed->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get bed analytics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function analyticsOverview(Request $request): JsonResponse
    {
        $farmId = $request->input('farm_id') ?? $request->header('X-Tenant-ID');

        $validator = Validator::make(['farm_id' => $farmId], [
            'farm_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $beds = Bed::with('latestAnalytics')
            ->where('farm_id', $farmId)
            ->active()
            ->orderBy('layout_order')
            ->get();

        $scores = $beds->map(fn (Bed $bed) => (float) ($bed->latestAnalytics?->overall_performance_score
            ?? $bed->getPerformanceSummary()['overall_score']));
        $farmAverage = $scores->isNotEmpty() ? (float) $scores->average() : 0.0;
        $bestScore = $scores->isNotEmpty() ? (float) $scores->max() : 0.0;
        $rankings = $beds
            ->sortByDesc(fn (Bed $bed) => (float) ($bed->latestAnalytics?->overall_performance_score
                ?? $bed->getPerformanceSummary()['overall_score']))
            ->values()
            ->mapWithKeys(fn (Bed $bed, int $index) => [$bed->id => $index + 1]);

        $analytics = $beds->map(function (Bed $bed) use ($farmAverage, $bestScore, $rankings) {
            $latest = $bed->latestAnalytics;
            $yield = (float) ($latest?->total_yield_kg ?? $bed->total_yield_kg ?? 0);
            $revenue = (float) ($latest?->total_revenue ?? $bed->total_revenue ?? 0);
            $costs = (float) ($latest?->total_costs
                ?? ((float) $bed->total_labour_cost + (float) $bed->total_input_cost));
            $profit = (float) ($latest?->net_profit ?? ($revenue - $costs));
            $roi = $costs > 0 ? ($profit / $costs) * 100 : 0.0;
            $overall = (float) ($latest?->overall_performance_score
                ?? $bed->getPerformanceSummary()['overall_score']);

            $trend = static fn ($value) => (float) $value > 2
                ? 'improving'
                : ((float) $value < -2 ? 'declining' : 'stable');
            $costTrend = (float) ($latest?->cost_trend_percentage ?? 0);

            return [
                'id' => $latest?->id ?? 'overview-' . $bed->id,
                'bed_id' => $bed->id,
                'farm_id' => $bed->farm_id,
                'analysis_date' => now()->toDateString(),
                'analysis_type' => match ($latest?->analysis_period) {
                    'weekly' => 'weekly',
                    'seasonal' => 'seasonal',
                    'annual' => 'yearly',
                    default => 'monthly',
                },
                'metrics' => [
                    'yield_kg' => $yield,
                    'revenue' => $revenue,
                    'costs' => $costs,
                    'profit' => $profit,
                    'roi_percentage' => (float) ($latest?->roi_percentage ?? $roi),
                    'yield_per_sqm' => (float) ($latest?->yield_per_sqm ?? ($bed->size_area > 0 ? $yield / (float) $bed->size_area : 0)),
                    'cost_per_kg' => (float) ($latest?->cost_per_kg ?? ($yield > 0 ? $costs / $yield : 0)),
                ],
                'efficiency' => [
                    'overall_score' => $overall,
                    'yield_efficiency' => (float) ($latest?->yield_performance_score ?? $bed->yield_performance_score),
                    'cost_efficiency' => (float) ($latest?->cost_efficiency_score ?? $bed->cost_efficiency_score),
                    'time_efficiency' => (float) ($latest?->timeline_performance_score ?? $bed->reliability_score),
                    'resource_efficiency' => (float) ($latest?->water_efficiency_score ?? $overall),
                ],
                'comparison' => [
                    'vs_farm_average' => $overall - $farmAverage,
                    'vs_previous_period' => (float) ($latest?->farm_average_comparison ?? 0),
                    'vs_best_performing_bed' => $overall - $bestScore,
                    'ranking_among_beds' => (int) ($rankings[$bed->id] ?? 0),
                ],
                'trends' => [
                    'yield_trend' => $trend($latest?->yield_trend_percentage ?? 0),
                    'cost_trend' => $trend(-$costTrend),
                    'health_trend' => $trend($latest?->health_trend_percentage ?? 0),
                    'efficiency_trend' => $trend($latest?->farm_average_comparison ?? 0),
                ],
                'recommendations' => collect($latest?->recommendations ?? $bed->getRecommendations())
                    ->map(fn ($item) => [
                        'type' => $item['type'] ?? 'layout',
                        'priority' => $item['priority'] ?? 'medium',
                        'title' => $item['title'] ?? 'Review bed performance',
                        'description' => $item['description'] ?? '',
                        'expected_impact' => 0,
                        'effort_required' => 'medium',
                    ])->values(),
                'created_at' => $latest?->created_at?->toISOString() ?? now()->toISOString(),
                'updated_at' => $latest?->updated_at?->toISOString() ?? now()->toISOString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $analytics,
        ]);
    }

    public function farmLayout(Request $request): JsonResponse
    {
        $farmId = $request->farm_id;
        
        if (!$farmId) {
            return response()->json([
                'success' => false,
                'message' => 'Farm ID is required',
            ], 422);
        }

        try {
            $beds = Bed::where('farm_id', $farmId)
                ->active()
                ->byLayout()
                ->with('currentCropAssignment')
                ->get();

            // Group beds by layout for grid display
            $layout = [];
            $maxRow = 0;
            $maxCol = 0;

            foreach ($beds as $bed) {
                if ($bed->layout_row && $bed->layout_column) {
                    $layout[$bed->layout_row][$bed->layout_column] = $bed;
                    $maxRow = max($maxRow, $bed->layout_row);
                    $maxCol = max($maxCol, $bed->layout_column);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'beds' => $beds->map(function($bed) {
                        return [
                            'id' => $bed->id,
                            'name' => $bed->name,
                            'bed_type' => $bed->bed_type,
                            'status' => $bed->status,
                            'health_score' => $bed->health_score,
                            'layout_order' => $bed->layout_order,
                            'layout_row' => $bed->layout_row,
                            'layout_column' => $bed->layout_column,
                            'size_area' => $bed->size_area,
                            'current_crop' => $bed->currentCropAssignment ? [
                                'crop_name' => $bed->currentCropAssignment->crop_name,
                                'variety' => $bed->currentCropAssignment->variety,
                                'days_planted' => $bed->currentCropAssignment->getDurationDays()
                            ] : null,
                            'is_available' => $bed->isAvailable(),
                            'needs_maintenance' => $bed->needs_maintenance,
                            'performance_summary' => $bed->getPerformanceSummary()
                        ];
                    }),
                    'layout_grid' => $layout,
                    'layout_dimensions' => [
                        'max_row' => $maxRow,
                        'max_column' => $maxCol
                    ],
                    'summary' => [
                        'total_beds' => $beds->count(),
                        'available_beds' => $beds->filter(fn($bed) => $bed->isAvailable())->count(),
                        'planted_beds' => $beds->where('status', 'planted')->count(),
                        'beds_with_issues' => $beds->where('status', 'issues_detected')->count(),
                        'beds_needing_maintenance' => $beds->where('needs_maintenance', true)->count(),
                        'average_health_score' => round($beds->avg('health_score'), 1),
                        'total_area' => $beds->sum('size_area')
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get farm layout', [
                'farm_id' => $farmId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get farm layout',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Helper methods

    private function getProductivityTrends(Bed $bed): array
    {
        $assignments = $bed->cropAssignments()
            ->where('assignment_status', 'completed')
            ->orderBy('actual_end_date', 'desc')
            ->limit(6)
            ->get();

        return [
            'yield_trend' => $assignments->pluck('actual_yield_kg', 'actual_end_date'),
            'cost_trend' => $assignments->pluck('cost_per_kg', 'actual_end_date'),
            'efficiency_trend' => $assignments->pluck('yield_efficiency_score', 'actual_end_date')
        ];
    }

    private function getHealthTimeline(Bed $bed): array
    {
        $notes = $bed->notes()
            ->orderBy('note_date', 'desc')
            ->limit(10)
            ->get(['note_date', 'severity', 'note_type', 'is_resolved']);

        return $notes->map(function($note) {
            return [
                'date' => $note->note_date,
                'severity' => $note->severity,
                'type' => $note->note_type,
                'resolved' => $note->is_resolved
            ];
        })->toArray();
    }

    private function calculateTrends(Collection $analytics): array
    {
        if ($analytics->count() < 2) {
            return ['insufficient_data' => true];
        }

        $latest = $analytics->first();
        $previous = $analytics->skip(1)->first();

        return [
            'yield_trend' => $this->calculatePercentageChange(
                $previous->yield_performance_score, 
                $latest->yield_performance_score
            ),
            'cost_trend' => $this->calculatePercentageChange(
                $previous->cost_efficiency_score, 
                $latest->cost_efficiency_score
            ),
            'health_trend' => $this->calculatePercentageChange(
                $previous->average_health_score, 
                $latest->average_health_score
            ),
            'overall_trend' => $this->calculatePercentageChange(
                $previous->overall_performance_score, 
                $latest->overall_performance_score
            )
        ];
    }

    private function calculatePercentageChange($oldValue, $newValue): float
    {
        if (!$oldValue || $oldValue == 0) return 0;
        return round((($newValue - $oldValue) / $oldValue) * 100, 2);
    }

    private function getComparativeAnalysis(Bed $bed): array
    {
        $farmBeds = Bed::where('farm_id', $bed->farm_id)
            ->where('id', '!=', $bed->id)
            ->active()
            ->get();

        if ($farmBeds->isEmpty()) {
            return ['insufficient_data' => true];
        }

        $farmAvgs = [
            'health_score' => $farmBeds->avg('health_score'),
            'yield_performance' => $farmBeds->avg('yield_performance_score'),
            'cost_efficiency' => $farmBeds->avg('cost_efficiency_score')
        ];

        return [
            'farm_averages' => $farmAvgs,
            'bed_vs_farm' => [
                'health_score' => $bed->health_score - $farmAvgs['health_score'],
                'yield_performance' => $bed->yield_performance_score - $farmAvgs['yield_performance'],
                'cost_efficiency' => $bed->cost_efficiency_score - $farmAvgs['cost_efficiency']
            ],
            'farm_ranking' => $this->calculateFarmRanking($bed, $farmBeds)
        ];
    }

    private function calculateFarmRanking(Bed $bed, Collection $farmBeds): int
    {
        $allBeds = $farmBeds->push($bed);
        $ranked = $allBeds->sortByDesc(function($b) {
            return $b->yield_performance_score + $b->cost_efficiency_score + $b->health_score;
        })->values();

        return $ranked->search(function($b) use ($bed) {
            return $b->id === $bed->id;
        }) + 1;
    }

    private function getProductivityMetrics(Bed $bed): array
    {
        return [
            'total_cycles_completed' => $bed->total_crop_cycles,
            'lifetime_yield' => $bed->total_yield_kg,
            'lifetime_revenue' => $bed->total_revenue,
            'lifetime_costs' => $bed->total_labour_cost + $bed->total_input_cost,
            'lifetime_profit' => $bed->total_revenue - ($bed->total_labour_cost + $bed->total_input_cost),
            'average_cycle_duration' => $this->getAverageCycleDuration($bed),
            'utilization_rate' => $bed->getUtilizationRate(),
            'yield_per_sqm' => $bed->size_area ? $bed->total_yield_kg / $bed->size_area : 0
        ];
    }

    private function getAverageCycleDuration(Bed $bed): float
    {
        $completedAssignments = $bed->cropAssignments()
            ->where('assignment_status', 'completed')
            ->whereNotNull('days_to_harvest')
            ->get();

        return $completedAssignments->avg('days_to_harvest') ?? 0;
    }

    public function destroy(Bed $bed): JsonResponse
    {
        try {
            if ($bed->currentCropAssignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete bed with active crop assignment. Complete or cancel the assignment first.',
                ], 422);
            }

            // Soft delete by archiving
            $bed->archive('Bed deleted by user');

            Log::info('Bed deleted', [
                'bed_id' => $bed->id,
                'deleted_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bed deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete bed', [
                'bed_id' => $bed->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete bed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
