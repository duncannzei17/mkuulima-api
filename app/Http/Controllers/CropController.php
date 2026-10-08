<?php

namespace App\Http\Controllers;

use App\Models\CropCycle;
use App\Models\CropTask;
use App\Models\CropProgressLog;
use App\Models\CropInput;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CropController extends Controller
{
    /**
     * Get all crop cycles for the current farm tenant
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $status = $request->query('status');
            $cropName = $request->query('crop_name');
            $limit = $request->query('limit', 20);

            $query = CropCycle::query();

            if ($status) {
                $query->byStatus($status);
            }

            if ($cropName) {
                $query->byCrop($cropName);
            }

            $crops = $query->orderBy('created_at', 'desc')
                          ->paginate($limit);

            // Add calculated fields
            $crops->getCollection()->transform(function ($crop) {
                return [
                    'id' => $crop->id,
                    'crop_name' => $crop->crop_name,
                    'variety' => $crop->variety,
                    'season_name' => $crop->season_name,
                    'start_date' => $crop->start_date,
                    'expected_harvest_date' => $crop->expected_harvest_date,
                    'actual_harvest_date' => $crop->actual_harvest_date,
                    'season_status' => $crop->season_status,
                    'health_status' => $crop->health_status,
                    'land_area' => $crop->land_area,
                    'area_unit' => $crop->area_unit,
                    'bed_ids' => $crop->bed_ids,
                    'expected_yield' => $crop->expected_yield,
                    'actual_yield' => $crop->actual_yield,
                    'yield_unit' => $crop->yield_unit,
                    'growth_progress' => $crop->growth_progress,
                    'days_to_harvest' => $crop->days_to_harvest,
                    'is_overdue' => $crop->is_overdue,
                    'current_stage' => $crop->current_stage,
                    'latest_health_rating' => $crop->latest_health_rating,
                    'tasks_count' => $crop->tasks()->count(),
                    'completed_tasks_count' => $crop->tasks()->where('status', 'completed')->count(),
                    'pending_tasks_count' => $crop->tasks()->where('status', 'scheduled')->count(),
                    'overdue_tasks_count' => $crop->tasks()->where('status', 'scheduled')->where('scheduled_date', '<', now())->count(),
                    'total_input_cost' => 0, // TODO: Implement when inputs table exists
                    'estimated_cost' => $crop->estimated_cost,
                    'actual_cost' => $crop->actual_cost,
                    'estimated_revenue' => $crop->estimated_revenue,
                    'actual_revenue' => $crop->actual_revenue,
                    'profit_margin' => $crop->profit_margin,
                    'created_at' => $crop->created_at,
                    'updated_at' => $crop->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $crops->items(),
                'pagination' => [
                    'current_page' => $crops->currentPage(),
                    'last_page' => $crops->lastPage(),
                    'per_page' => $crops->perPage(),
                    'total' => $crops->total(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve crop cycles',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Create a new crop cycle
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'crop_name' => 'required|string|max:100',
                'variety' => 'nullable|string|max:100',
                'start_date' => 'required|date|after_or_equal:' . now()->subDays(30)->format('Y-m-d'),
                'expected_harvest_date' => 'required|date|after:start_date',
                'bed_ids' => 'nullable|array',
                'land_area' => 'nullable|numeric|min:0.01|max:10000',
                'land_area_unit' => 'sometimes|string|in:acres,hectares,square_meters',
                
                // Nursery information (optional)
                'has_nursery' => 'sometimes|boolean',
                'nursery_start_date' => 'nullable|date|before_or_equal:start_date',
                'seed_quantity' => 'nullable|integer|min:1',
                'seed_unit' => 'nullable|string|in:grams,kg,seeds,packets',
                'expected_transplant_date' => 'nullable|date|after:nursery_start_date|before_or_equal:start_date',
                
                // Additional information
                'notes' => 'nullable|string|max:1000',
                'planned_inputs' => 'nullable|array',
                'planned_tasks' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check for bed conflicts (if Module 11 is implemented)
            if ($request->bed_ids) {
                $conflictingCrops = CropCycle::active()
                    ->where(function($query) use ($request) {
                        foreach ($request->bed_ids as $bedId) {
                            $query->orWhereJsonContains('bed_ids', $bedId);
                        }
                    })
                    ->exists();

                if ($conflictingCrops) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bed allocation conflict. One or more selected beds are already assigned to active crops.',
                        'error_type' => 'bed_conflict'
                    ], 409);
                }
            }

            // Create crop cycle
            $cropData = $validator->validated();
            
            // Get the farm ID from the tenant context (usually from X-Tenant-ID header)
            $farmId = $request->header('X-Tenant-ID') ?? $request->header('X-Farm-Context');
            if (!$farmId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Farm context is required'
                ], 400);
            }

            DB::beginTransaction();
            
            $cropData['farm_id'] = $farmId;
            $cropData['land_area_unit'] = $cropData['land_area_unit'] ?? 'acres';
            $cropData['has_nursery'] = $cropData['has_nursery'] ?? false;
            $cropData['season_status'] = $cropData['has_nursery'] ? 'nursery' : 'planning';

            $crop = CropCycle::create($cropData);

            // TODO: Fix task creation method
            // Create default tasks based on crop lifecycle
            // $this->createDefaultTasks($crop, $request);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Crop cycle created successfully',
                'data' => [
                    'crop' => [
                        'id' => $crop->id,
                        'crop_name' => $crop->crop_name,
                        'variety' => $crop->variety,
                        'start_date' => $crop->start_date,
                        'expected_harvest_date' => $crop->expected_harvest_date,
                        'season_status' => $crop->season_status,
                        'land_area' => $crop->land_area,
                        'land_area_unit' => $crop->land_area_unit,
                        'has_nursery' => $crop->has_nursery,
                        'nursery_start_date' => $crop->nursery_start_date,
                        'expected_transplant_date' => $crop->expected_transplant_date,
                        'seed_quantity' => $crop->seed_quantity,
                        'seed_unit' => $crop->seed_unit,
                        'notes' => $crop->notes,
                        'bed_ids' => $crop->bed_ids,
                        'planned_tasks' => $crop->planned_tasks,
                        'planned_inputs' => $crop->planned_inputs,
                        'created_at' => $crop->created_at,
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            report($e);
            
            return response()->json([
                'success' => false,
                'message' => 'Crop cycle creation failed. Please try again.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get detailed information about a specific crop cycle
     */
    public function show(Request $request, string $cropId): JsonResponse
    {
        try {
            $crop = CropCycle::with([
                'tasks' => function($query) {
                    $query->orderBy('scheduled_date', 'asc');
                },
                'progressLogs' => function($query) {
                    $query->orderBy('log_date', 'desc')->limit(10);
                },
                // 'inputs' => function($query) {
                //     $query->orderBy('planned_application_date', 'asc');
                // }
            ])->findOrFail($cropId);

            // Get harvest forecast
            $harvestForecast = $crop->getHarvestForecast();

            // Get recent progress summary
            $recentProgress = $crop->progressLogs()
                                  ->recent(7)
                                  ->orderBy('log_date', 'desc')
                                  ->get()
                                  ->map(function($log) {
                                      return [
                                          'date' => $log->log_date,
                                          'health_rating' => $log->health_rating,
                                          'growth_stage' => $log->growth_stage,
                                          'activities' => count($log->activities_done ?? []),
                                          'issues' => $log->total_issues_count,
                                          'observations' => $log->observations,
                                      ];
                                  });

            return response()->json([
                'success' => true,
                'data' => [
                    'crop' => [
                        'id' => $crop->id,
                        'crop_name' => $crop->crop_name,
                        'variety' => $crop->variety,
                        'season_name' => $crop->season_name,
                        'start_date' => $crop->start_date,
                        'expected_harvest_date' => $crop->expected_harvest_date,
                        'actual_harvest_date' => $crop->actual_harvest_date,
                        'season_status' => $crop->season_status,
                        'health_status' => $crop->health_status,
                        'land_area' => $crop->land_area,
                        'area_unit' => $crop->area_unit,
                        'bed_ids' => $crop->bed_ids,
                        'expected_yield' => $crop->expected_yield,
                        'actual_yield' => $crop->actual_yield,
                        'yield_unit' => $crop->yield_unit,
                        'has_nursery' => $crop->has_nursery,
                        'nursery_start_date' => $crop->nursery_start_date,
                        'seed_quantity' => $crop->seed_quantity,
                        'expected_transplant_date' => $crop->expected_transplant_date,
                        'actual_transplant_date' => $crop->actual_transplant_date,
                        'seedlings_transplanted' => $crop->seedlings_transplanted,
                        'estimated_cost' => $crop->estimated_cost,
                        'actual_cost' => $crop->actual_cost,
                        'estimated_revenue' => $crop->estimated_revenue,
                        'actual_revenue' => $crop->actual_revenue,
                        'profit_margin' => $crop->profit_margin,
                        'irrigation_required' => $crop->irrigation_required,
                        'planting_notes' => $crop->planting_notes,
                        'general_notes' => $crop->general_notes,
                        'weather_conditions' => $crop->weather_conditions,
                        'growth_progress' => $crop->growth_progress,
                        'days_to_harvest' => $crop->days_to_harvest,
                        'days_from_planting' => $crop->days_from_planting,
                        'is_overdue' => $crop->is_overdue,
                        'current_stage' => $crop->current_stage,
                        'latest_health_rating' => $crop->latest_health_rating,
                        'created_at' => $crop->created_at,
                        'updated_at' => $crop->updated_at,
                    ],
                    'tasks' => $crop->tasks,
                    'recent_progress' => $recentProgress,
                    'inputs' => [], // TODO: Implement inputs relationship
                    'harvest_forecast' => $harvestForecast,
                    'summary' => [
                        'total_tasks' => $crop->tasks->count(),
                        'completed_tasks' => $crop->getCompletedTasksCount(),
                        'pending_tasks' => $crop->getPendingTasksCount(),
                        'overdue_tasks' => $crop->getOverdueTasksCount(),
                        'total_input_cost' => $crop->getTotalInputCost(),
                        'progress_logs_count' => $crop->progressLogs->count(),
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve crop details',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update crop cycle information
     */
    public function update(Request $request, string $cropId): JsonResponse
    {
        try {
            $crop = CropCycle::findOrFail($cropId);

            // Check if crop can be edited
            if (!$crop->canBeEdited()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot edit completed or archived crops'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'crop_name' => 'sometimes|string|max:100',
                'variety' => 'sometimes|nullable|string|max:100',
                'season_name' => 'sometimes|nullable|string|max:100',
                'expected_harvest_date' => 'sometimes|date|after:start_date',
                'bed_ids' => 'sometimes|nullable|array',
                'land_area' => 'sometimes|nullable|numeric|min:0.01|max:10000',
                'area_unit' => 'sometimes|string|in:acres,hectares,square_meters',
                'expected_yield' => 'sometimes|nullable|numeric|min:0',
                'yield_unit' => 'sometimes|string|in:kg,tons,bags,pieces',
                'estimated_cost' => 'sometimes|nullable|numeric|min:0',
                'estimated_revenue' => 'sometimes|nullable|numeric|min:0',
                'irrigation_required' => 'sometimes|boolean',
                'planting_notes' => 'sometimes|nullable|string|max:1000',
                'general_notes' => 'sometimes|nullable|string|max:1000',
                'weather_conditions' => 'sometimes|nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $crop->update($validator->validated());

            return response()->json([
                'success' => true,
                'message' => 'Crop cycle updated successfully',
                'data' => [
                    'crop' => $crop
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Crop cycle update failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update crop status (lifecycle progression)
     */
    public function updateStatus(Request $request, string $cropId): JsonResponse
    {
        try {
            $crop = CropCycle::findOrFail($cropId);

            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:planned,nursery,transplanted,growing,harvest,completed',
                'notes' => 'nullable|string|max:500',
                'actual_transplant_date' => 'nullable|date',
                'seedlings_transplanted' => 'nullable|integer|min:1',
                'actual_harvest_date' => 'nullable|date',
                'actual_yield' => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Update status and related fields
            $crop->updateStatus($request->status);

            if ($request->actual_transplant_date) {
                $crop->actual_transplant_date = $request->actual_transplant_date;
            }

            if ($request->seedlings_transplanted) {
                $crop->seedlings_transplanted = $request->seedlings_transplanted;
            }

            if ($request->actual_harvest_date) {
                $crop->actual_harvest_date = $request->actual_harvest_date;
            }

            if ($request->actual_yield) {
                $crop->actual_yield = $request->actual_yield;
            }

            if ($request->notes) {
                $crop->general_notes = ($crop->general_notes ? $crop->general_notes . "\n\n" : '') . 
                                      now()->format('Y-m-d') . ": " . $request->notes;
            }

            $crop->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Crop status updated successfully',
                'data' => [
                    'crop' => $crop
                ]
            ]);

        } catch (\InvalidArgumentException $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Status update failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Archive/close a crop cycle
     */
    public function archive(Request $request, string $cropId): JsonResponse
    {
        try {
            $crop = CropCycle::findOrFail($cropId);

            $validator = Validator::make($request->all(), [
                'reason' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $crop->archive($request->reason);

            return response()->json([
                'success' => true,
                'message' => 'Crop cycle archived successfully',
                'data' => [
                    'crop' => $crop
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Crop archiving failed',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get crop analytics and insights
     */
    public function analytics(Request $request): JsonResponse
    {
        try {
            $period = $request->query('period', '6_months');
            $cropName = $request->query('crop_name');

            $query = CropCycle::query();

            // Filter by period
            switch ($period) {
                case '1_month':
                    $query->where('start_date', '>=', now()->subMonth());
                    break;
                case '3_months':
                    $query->where('start_date', '>=', now()->subMonths(3));
                    break;
                case '6_months':
                    $query->where('start_date', '>=', now()->subMonths(6));
                    break;
                case '1_year':
                    $query->where('start_date', '>=', now()->subYear());
                    break;
            }

            if ($cropName) {
                $query->byCrop($cropName);
            }

            $crops = $query->get();

            // Calculate task statistics for active crops
            $activeCropIds = $crops->where('season_status', '!=', 'completed')->pluck('id');
            $pendingTasks = \App\Models\CropTask::whereIn('crop_cycle_id', $activeCropIds)
                ->where('status', 'scheduled')
                ->count();
            
            $overdueTasks = \App\Models\CropTask::whereIn('crop_cycle_id', $activeCropIds)
                ->where('status', 'scheduled')
                ->where('scheduled_date', '<', now()->toDateString())
                ->count();

            // Calculate analytics - return data at root level for frontend compatibility
            $analytics = [
                'total_crops' => $crops->count(),
                'active_crops' => $crops->where('season_status', '!=', 'completed')->count(),
                'completed_crops' => $crops->where('season_status', 'completed')->count(),
                'tasks_pending' => $pendingTasks,
                'tasks_overdue' => $overdueTasks,
                'total_area' => $crops->sum('land_area'),
                'total_yield' => $crops->sum('actual_yield'),
                'total_cost' => $crops->sum('actual_cost'),
                'total_revenue' => $crops->sum('actual_revenue'),
                'average_profit_margin' => $crops->whereNotNull('profit_margin')->avg('profit_margin'),
                
                'crop_distribution' => $crops->groupBy('crop_name')
                                           ->map(function($group) {
                                               return $group->count();
                                           }),
                
                'status_distribution' => $crops->groupBy('season_status')
                                              ->map(function($group) {
                                                  return $group->count();
                                              }),
                
                'health_distribution' => $crops->groupBy('health_status')
                                              ->map(function($group) {
                                                  return $group->count();
                                              }),
                
                'monthly_plantings' => $crops->groupBy(function($crop) {
                                                return $crop->start_date->format('Y-m');
                                            })
                                            ->map(function($group) {
                                                return $group->count();
                                            }),
                
                'yield_per_acre' => $crops->where('land_area', '>', 0)
                                         ->map(function($crop) {
                                             return $crop->actual_yield ? 
                                                   $crop->actual_yield / $crop->land_area : 0;
                                         })
                                         ->avg(),
            ];

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate analytics',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Create default tasks for a new crop cycle
     */
    private function createDefaultTasks(CropCycle $crop, Request $request)
    {
        $tasks = [];
        $startDate = $crop->start_date;

        // Nursery tasks (if applicable)
        if ($crop->has_nursery && $crop->nursery_start_date) {
            $tasks[] = [
                'task_name' => 'Nursery Setup',
                'task_type' => 'planting',
                'description' => 'Prepare nursery bed and plant seeds',
                'scheduled_date' => $crop->nursery_start_date,
                'estimated_duration' => 240,
            ];

            if ($crop->expected_transplant_date) {
                $tasks[] = [
                    'task_name' => 'Transplanting',
                    'task_type' => 'planting',
                    'description' => 'Transplant seedlings from nursery to main beds',
                    'scheduled_date' => $crop->expected_transplant_date,
                    'estimated_duration' => 360,
                ];
            }
        } else {
            $tasks[] = [
                'task_name' => 'Direct Planting',
                'task_type' => 'planting',
                'description' => 'Direct plant seeds in main beds',
                'scheduled_date' => $startDate,
                'estimated_duration' => 240,
            ];
        }

        // Common crop tasks
        $tasks[] = [
            'task_name' => 'First Weeding',
            'task_type' => 'weeding',
            'description' => 'Remove weeds around young plants',
            'scheduled_date' => $startDate->copy()->addWeeks(2),
            'estimated_duration' => 180,
        ];

        $tasks[] = [
            'task_name' => 'Fertilizer Application',
            'task_type' => 'fertilizing',
            'description' => 'Apply base fertilizer',
            'scheduled_date' => $startDate->copy()->addWeeks(3),
            'estimated_duration' => 120,
        ];

        $tasks[] = [
            'task_name' => 'Pest Monitoring',
            'task_type' => 'pest_control',
            'description' => 'Check for pest and disease issues',
            'scheduled_date' => $startDate->copy()->addWeeks(4),
            'estimated_duration' => 60,
        ];

        if ($crop->irrigation_required) {
            $tasks[] = [
                'task_name' => 'Irrigation Setup',
                'task_type' => 'watering',
                'description' => 'Set up irrigation system',
                'scheduled_date' => $startDate->copy()->addDays(1),
                'estimated_duration' => 120,
            ];
        }

        // Harvest task
        $tasks[] = [
            'task_name' => 'Harvest',
            'task_type' => 'harvesting',
            'description' => 'Harvest mature crops',
            'scheduled_date' => $crop->expected_harvest_date->copy()->subDays(2),
            'estimated_duration' => 480,
        ];

        // Create all tasks
        foreach ($tasks as $taskData) {
            $crop->scheduleTask($taskData);
        }
    }

    /**
     * Get tasks for a specific crop cycle
     */
    public function getTasks($cropId): JsonResponse
    {
        try {
            $crop = CropCycle::findOrFail($cropId);
            $tasks = $crop->tasks()->orderBy('scheduled_date')->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'tasks' => $tasks->map(function ($task) {
                        return [
                            'id' => $task->id,
                            'task_name' => $task->task_name,
                            'task_type' => $task->task_type,
                            'description' => $task->description,
                            'scheduled_date' => $task->scheduled_date,
                            'scheduled_time' => $task->scheduled_time,
                            'completed_date' => $task->completed_date,
                            'status' => $task->status,
                            'priority' => $task->priority,
                            'estimated_duration' => $task->estimated_duration,
                            'actual_duration' => $task->actual_duration,
                            'completion_notes' => $task->completion_notes,
                            'created_at' => $task->created_at,
                        ];
                    })
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve tasks',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Create a new task for a crop cycle
     */
    public function createTask($cropId, Request $request): JsonResponse
    {
        try {
            $crop = CropCycle::findOrFail($cropId);

            $validator = Validator::make($request->all(), [
                'task_name' => 'required|string|max:255',
                'task_type' => 'required|string|in:planting,watering,fertilizing,weeding,pest_control,disease_management,pruning,harvesting,general,other',
                'description' => 'nullable|string|max:1000',
                'scheduled_date' => 'required|date|after_or_equal:today',
                'scheduled_time' => 'nullable|date_format:H:i',
                'priority' => 'nullable|string|in:low,medium,high,urgent',
                'estimated_duration' => 'nullable|integer|min:5|max:1440', // 5 minutes to 24 hours
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $taskData = $validator->validated();
            $taskData['crop_cycle_id'] = $crop->id;
            $taskData['priority'] = $taskData['priority'] ?? 'medium';
            $taskData['status'] = 'scheduled';

            $task = CropTask::create($taskData);
            $taskResponse = $task->toArray();
            $taskResponse['scheduled_date'] = $task->scheduled_date->toDateString();
            $taskResponse['crop_name'] = $crop->crop_name;
            $taskResponse['crop_variety'] = $crop->variety;

            return response()->json([
                'success' => true,
                'message' => 'Task created successfully',
                'data' => ['task' => $taskResponse]
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create task',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update a task status
     */
    public function updateTaskStatus($taskId, Request $request): JsonResponse
    {
        try {
            $task = CropTask::findOrFail($taskId);

            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:scheduled,in_progress,completed,cancelled',
                'completion_notes' => 'nullable|string|max:1000',
                'actual_duration' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $task->status = $request->status;
            
            if ($request->completion_notes) {
                $task->completion_notes = $request->completion_notes;
            }

            if ($request->status === 'completed') {
                $task->completed_date = now()->toDateString();
                $task->completed_time = now()->format('H:i:s');
                
                if ($request->actual_duration) {
                    $task->actual_duration = $request->actual_duration;
                }
            }

            $task->save();

            return response()->json([
                'success' => true,
                'message' => 'Task status updated successfully',
                'data' => ['task' => $task]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task status',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}
