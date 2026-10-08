<?php

namespace App\Http\Controllers;

use App\Models\BedNote;
use App\Models\Bed;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class BedNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BedNote::with(['bed', 'createdBy', 'approvedBy'])
            ->where('farm_id', $request->farm_id);

        // Filter by bed
        if ($request->has('bed_id')) {
            $query->where('bed_id', $request->bed_id);
        }

        // Filter by note type
        if ($request->has('note_type')) {
            $query->where('note_type', $request->note_type);
        }

        // Filter by severity
        if ($request->has('severity')) {
            $query->where('severity', $request->severity);
        }

        // Filter by resolution status
        if ($request->has('resolved')) {
            $query->where('is_resolved', $request->boolean('resolved'));
        }

        // Filter requiring action
        if ($request->boolean('requires_action')) {
            $query->where('requires_action', true);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->where('note_date', '>=', $request->from_date);
        }
        
        if ($request->has('to_date')) {
            $query->where('note_date', '<=', $request->to_date);
        }

        // Filter critical notes
        if ($request->boolean('critical_only')) {
            $query->where('severity', 'critical');
        }

        // Filter recurring issues
        if ($request->boolean('recurring_only')) {
            $query->where('recurring_issue', true);
        }

        // Search in note content
        if ($request->has('search')) {
            $query->where('note_content', 'ILIKE', '%' . $request->search . '%');
        }

        // Sorting
        $sortField = $request->get('sort_by', 'note_date');
        $sortDirection = $request->get('sort_direction', 'desc');
        
        $allowedSortFields = [
            'note_date', 'severity', 'note_type', 'requires_action', 
            'is_resolved', 'created_at'
        ];
        
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection);
        }

        $notes = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $notes->map(function ($note) {
                return array_merge($note->toArray(), [
                    'recommendation' => $note->getRecommendation(),
                    'similar_notes_count' => $note->getSimilarNotes(6)->count(),
                    'is_overdue' => $note->isOverdue(),
                    'days_until_action' => $note->getDaysUntilAction()
                ]);
            }),
            'pagination' => [
                'current_page' => $notes->currentPage(),
                'last_page' => $notes->lastPage(),
                'per_page' => $notes->perPage(),
                'total' => $notes->total(),
            ],
            'summary' => $this->getNoteSummary($request->farm_id, $request->bed_id)
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'bed_id' => 'required|uuid|exists:beds,id',
            'farm_id' => 'required|uuid|exists:farms,id',
            'note_date' => 'required|date|before_or_equal:today',
            'note_content' => 'required|string|min:10|max:2000',
            'note_type' => 'required|in:' . implode(',', array_keys(BedNote::NOTE_TYPES)),
            'severity' => 'required|in:' . implode(',', array_keys(BedNote::SEVERITIES)),
            'requires_action' => 'boolean',
            'action_required_by' => 'nullable|date|after:today',
            'estimated_cost_impact' => 'nullable|numeric|min:0|max:1000000',
            'estimated_yield_impact' => 'nullable|numeric|min:0|max:10000',
            'affected_area_percentage' => 'nullable|integer|min:1|max:100',
            'specific_location' => 'nullable|string|max:100',
            'crop_cycle_id' => 'nullable|uuid|exists:crop_cycles,id',
            'days_after_planting' => 'nullable|integer|min:0|max:500',
            'growth_stage' => 'nullable|in:' . implode(',', array_keys(BedNote::GROWTH_STAGES)),
            'weather_conditions' => 'nullable|array',
            'temperature' => 'nullable|numeric|between:-50,70',
            'humidity' => 'nullable|numeric|between:0,100',
            'after_rain' => 'boolean',
            'during_irrigation' => 'boolean',
            'photos' => 'nullable|array',
            'photos.*' => 'string|url',
            'gps_latitude' => 'nullable|numeric|between:-90,90',
            'gps_longitude' => 'nullable|numeric|between:-180,180'
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

            $data = $validator->validated();
            $data['created_by'] = Auth::id();
            
            // Auto-approve if user is farm owner/manager
            $user = Auth::user();
            if ($user->can('approve', BedNote::class)) {
                $data['status'] = 'approved';
                $data['approved_by'] = Auth::id();
                $data['approved_at'] = now();
            } else {
                $data['status'] = 'submitted';
            }

            $note = BedNote::create($data);

            DB::commit();

            Log::info('Bed note created', [
                'note_id' => $note->id,
                'bed_id' => $note->bed_id,
                'severity' => $note->severity,
                'note_type' => $note->note_type,
                'created_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bed note created successfully',
                'data' => array_merge($note->load(['bed', 'createdBy'])->toArray(), [
                    'recommendation' => $note->getRecommendation(),
                    'similar_notes_count' => $note->getSimilarNotes(6)->count()
                ]),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create bed note', [
                'error' => $e->getMessage(),
                'bed_id' => $request->bed_id,
                'severity' => $request->severity
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create bed note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(BedNote $bedNote): JsonResponse
    {
        $bedNote->load(['bed', 'createdBy', 'approvedBy', 'followUpTask']);

        return response()->json([
            'success' => true,
            'data' => array_merge($bedNote->toArray(), [
                'recommendation' => $bedNote->getRecommendation(),
                'similar_notes' => $bedNote->getSimilarNotes(5)->map(function($note) {
                    return [
                        'id' => $note->id,
                        'note_date' => $note->note_date,
                        'severity' => $note->severity,
                        'note_content' => substr($note->note_content, 0, 100) . '...',
                        'is_resolved' => $note->is_resolved
                    ];
                }),
                'is_overdue' => $bedNote->isOverdue(),
                'days_until_action' => $bedNote->getDaysUntilAction()
            ]),
        ]);
    }

    public function update(Request $request, BedNote $bedNote): JsonResponse
    {
        // Only allow updates if note is not approved or user has permission
        if ($bedNote->status === 'approved' && !Auth::user()->can('update', $bedNote)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot update approved note without proper permissions',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'note_content' => 'sometimes|required|string|min:10|max:2000',
            'note_type' => 'sometimes|required|in:' . implode(',', array_keys(BedNote::NOTE_TYPES)),
            'severity' => 'sometimes|required|in:' . implode(',', array_keys(BedNote::SEVERITIES)),
            'requires_action' => 'boolean',
            'action_required_by' => 'nullable|date|after:today',
            'estimated_cost_impact' => 'nullable|numeric|min:0|max:1000000',
            'estimated_yield_impact' => 'nullable|numeric|min:0|max:10000',
            'affected_area_percentage' => 'nullable|integer|min:1|max:100',
            'specific_location' => 'nullable|string|max:100',
            'temperature' => 'nullable|numeric|between:-50,70',
            'humidity' => 'nullable|numeric|between:0,100',
            'photos' => 'nullable|array',
            'photos.*' => 'string|url',
            'resolution_notes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $validator->validated();
            $data['updated_by'] = Auth::id();

            $bedNote->update($data);

            Log::info('Bed note updated', [
                'note_id' => $bedNote->id,
                'updated_fields' => array_keys($data),
                'updated_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bed note updated successfully',
                'data' => array_merge($bedNote->fresh()->load(['bed', 'createdBy'])->toArray(), [
                    'recommendation' => $bedNote->getRecommendation()
                ]),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update bed note', [
                'note_id' => $bedNote->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update bed note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function approve(Request $request, BedNote $bedNote): JsonResponse
    {
        if (!Auth::user()->can('approve', $bedNote)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions to approve notes',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'approval_notes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $success = $bedNote->approve($request->approval_notes ?? '');

            if ($success) {
                Log::info('Bed note approved', [
                    'note_id' => $bedNote->id,
                    'approved_by' => Auth::id()
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Bed note approved successfully',
                    'data' => $bedNote->fresh()->load(['approvedBy']),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve bed note',
            ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to approve bed note', [
                'note_id' => $bedNote->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to approve bed note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function reject(Request $request, BedNote $bedNote): JsonResponse
    {
        if (!Auth::user()->can('approve', $bedNote)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permissions to reject notes',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'required|string|min:10|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $success = $bedNote->reject($request->rejection_reason);

            if ($success) {
                Log::info('Bed note rejected', [
                    'note_id' => $bedNote->id,
                    'rejected_by' => Auth::id(),
                    'reason' => $request->rejection_reason
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Bed note rejected successfully',
                    'data' => $bedNote->fresh()->load(['approvedBy']),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject bed note',
            ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to reject bed note', [
                'note_id' => $bedNote->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject bed note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function resolve(Request $request, BedNote $bedNote): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'resolution_notes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $success = $bedNote->resolve($request->resolution_notes ?? '');

            if ($success) {
                Log::info('Bed note resolved', [
                    'note_id' => $bedNote->id,
                    'resolved_by' => Auth::id()
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Bed note resolved successfully',
                    'data' => array_merge($bedNote->fresh()->toArray(), [
                        'bed_health_updated' => true
                    ]),
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve bed note',
            ], 500);

        } catch (\Exception $e) {
            Log::error('Failed to resolve bed note', [
                'note_id' => $bedNote->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve bed note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function timeline(Request $request, Bed $bed): JsonResponse
    {
        $months = $request->get('months', 6);
        
        try {
            $notes = $bed->notes()
                ->where('note_date', '>=', now()->subMonths($months))
                ->orderBy('note_date', 'desc')
                ->with(['createdBy'])
                ->get();

            $timeline = $notes->map(function($note) {
                return [
                    'id' => $note->id,
                    'date' => $note->note_date,
                    'type' => $note->note_type,
                    'severity' => $note->severity,
                    'content' => $note->note_content,
                    'requires_action' => $note->requires_action,
                    'is_resolved' => $note->is_resolved,
                    'resolved_date' => $note->resolved_date,
                    'created_by' => $note->createdBy->name ?? 'Unknown',
                    'cost_impact' => $note->estimated_cost_impact,
                    'yield_impact' => $note->estimated_yield_impact,
                    'recommendation' => $note->getRecommendation()
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'bed_info' => [
                        'id' => $bed->id,
                        'name' => $bed->name,
                        'current_health_score' => $bed->health_score
                    ],
                    'timeline' => $timeline,
                    'summary' => [
                        'total_notes' => $notes->count(),
                        'critical_issues' => $notes->where('severity', 'critical')->count(),
                        'unresolved_issues' => $notes->where('is_resolved', false)->count(),
                        'recurring_issues' => $notes->where('recurring_issue', true)->count(),
                        'most_common_type' => $this->getMostCommonIssueType($notes)
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get bed note timeline', [
                'bed_id' => $bed->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get bed note timeline',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function analytics(Request $request): JsonResponse
    {
        $farmId = $request->farm_id;
        $bedId = $request->bed_id;
        $months = $request->get('months', 12);

        try {
            $query = BedNote::where('farm_id', $farmId)
                ->where('note_date', '>=', now()->subMonths($months));

            if ($bedId) {
                $query->where('bed_id', $bedId);
            }

            $notes = $query->get();

            $analytics = [
                'summary' => [
                    'total_notes' => $notes->count(),
                    'critical_issues' => $notes->where('severity', 'critical')->count(),
                    'high_severity' => $notes->where('severity', 'high')->count(),
                    'unresolved_issues' => $notes->where('is_resolved', false)->count(),
                    'requiring_action' => $notes->where('requires_action', true)->count(),
                    'overdue_actions' => $notes->filter(fn($note) => $note->isOverdue())->count(),
                    'recurring_issues' => $notes->where('recurring_issue', true)->count()
                ],
                'by_severity' => $notes->groupBy('severity')->map(fn($group) => $group->count()),
                'by_type' => $notes->groupBy('note_type')->map(fn($group) => $group->count()),
                'by_month' => $notes->groupBy(function($note) {
                    return $note->note_date->format('Y-m');
                })->map(fn($group) => $group->count()),
                'resolution_stats' => [
                    'average_resolution_time' => $this->calculateAverageResolutionTime($notes),
                    'resolution_rate' => $notes->isNotEmpty() ? 
                        ($notes->where('is_resolved', true)->count() / $notes->count()) * 100 : 0
                ],
                'impact_analysis' => [
                    'total_estimated_cost_impact' => $notes->sum('estimated_cost_impact'),
                    'total_estimated_yield_impact' => $notes->sum('estimated_yield_impact'),
                    'average_cost_impact' => $notes->avg('estimated_cost_impact'),
                    'average_yield_impact' => $notes->avg('estimated_yield_impact')
                ],
                'bed_health_correlation' => $this->getBedHealthCorrelation($notes),
                'recommendations' => $this->generateAnalyticsRecommendations($notes)
            ];

            return response()->json([
                'success' => true,
                'data' => $analytics
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get bed note analytics', [
                'farm_id' => $farmId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get bed note analytics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function criticalIssues(Request $request): JsonResponse
    {
        $farmId = $request->farm_id;

        try {
            $criticalNotes = BedNote::with(['bed', 'createdBy'])
                ->where('farm_id', $farmId)
                ->where('severity', 'critical')
                ->where('is_resolved', false)
                ->orderBy('note_date', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $criticalNotes->map(function($note) {
                    return [
                        'id' => $note->id,
                        'bed_name' => $note->bed->name,
                        'bed_id' => $note->bed_id,
                        'note_date' => $note->note_date,
                        'note_type' => $note->note_type,
                        'note_content' => $note->note_content,
                        'estimated_cost_impact' => $note->estimated_cost_impact,
                        'estimated_yield_impact' => $note->estimated_yield_impact,
                        'requires_action' => $note->requires_action,
                        'action_required_by' => $note->action_required_by,
                        'is_overdue' => $note->isOverdue(),
                        'days_until_action' => $note->getDaysUntilAction(),
                        'recommendation' => $note->getRecommendation()
                    ];
                }),
                'summary' => [
                    'total_critical_issues' => $criticalNotes->count(),
                    'requiring_immediate_action' => $criticalNotes->where('requires_action', true)->count(),
                    'overdue_actions' => $criticalNotes->filter(fn($note) => $note->isOverdue())->count(),
                    'total_estimated_impact' => [
                        'cost' => $criticalNotes->sum('estimated_cost_impact'),
                        'yield' => $criticalNotes->sum('estimated_yield_impact')
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get critical issues', [
                'farm_id' => $farmId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get critical issues',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // Helper Methods

    private function getNoteSummary($farmId, $bedId = null): array
    {
        $query = BedNote::where('farm_id', $farmId);
        
        if ($bedId) {
            $query->where('bed_id', $bedId);
        }

        $notes = $query->get();

        return [
            'total_notes' => $notes->count(),
            'by_severity' => [
                'critical' => $notes->where('severity', 'critical')->count(),
                'high' => $notes->where('severity', 'high')->count(),
                'medium' => $notes->where('severity', 'medium')->count(),
                'low' => $notes->where('severity', 'low')->count()
            ],
            'by_status' => [
                'unresolved' => $notes->where('is_resolved', false)->count(),
                'resolved' => $notes->where('is_resolved', true)->count(),
                'requiring_action' => $notes->where('requires_action', true)->count(),
                'overdue' => $notes->filter(fn($note) => $note->isOverdue())->count()
            ],
            'recent_activity' => $notes->where('note_date', '>=', now()->subDays(7))->count()
        ];
    }

    private function getMostCommonIssueType($notes): ?string
    {
        $typeCounts = $notes->groupBy('note_type')->map(fn($group) => $group->count());
        return $typeCounts->isNotEmpty() ? $typeCounts->keys()->first() : null;
    }

    private function calculateAverageResolutionTime($notes): float
    {
        $resolvedNotes = $notes->filter(function($note) {
            return $note->is_resolved && $note->resolved_date;
        });

        if ($resolvedNotes->isEmpty()) {
            return 0;
        }

        $totalDays = $resolvedNotes->sum(function($note) {
            return $note->note_date->diffInDays($note->resolved_date);
        });

        return $totalDays / $resolvedNotes->count();
    }

    private function getBedHealthCorrelation($notes): array
    {
        // Group notes by bed and analyze health impact
        $bedGroups = $notes->groupBy('bed_id');
        
        $correlations = $bedGroups->map(function($bedNotes) {
            $bed = $bedNotes->first()->bed;
            $criticalCount = $bedNotes->where('severity', 'critical')->count();
            
            return [
                'bed_name' => $bed->name,
                'bed_health_score' => $bed->health_score,
                'critical_notes' => $criticalCount,
                'total_notes' => $bedNotes->count(),
                'health_impact_correlation' => $criticalCount > 0 ? 
                    max(0, 100 - ($criticalCount * 15)) : 100
            ];
        });

        return $correlations->values()->toArray();
    }

    private function generateAnalyticsRecommendations($notes): array
    {
        $recommendations = [];

        // High volume of critical issues
        $criticalCount = $notes->where('severity', 'critical')->count();
        if ($criticalCount > 5) {
            $recommendations[] = [
                'type' => 'critical_issues',
                'priority' => 'high',
                'message' => 'High number of critical issues detected. Implement immediate intervention protocols.',
                'action' => 'Review and prioritize resolution of critical issues'
            ];
        }

        // Recurring issue patterns
        $recurringCount = $notes->where('recurring_issue', true)->count();
        if ($recurringCount > 2) {
            $recommendations[] = [
                'type' => 'recurring_issues',
                'priority' => 'medium',
                'message' => 'Multiple recurring issues identified. Consider systematic preventive measures.',
                'action' => 'Implement preventive maintenance and monitoring protocols'
            ];
        }

        // Poor resolution rate
        $resolutionRate = $notes->isNotEmpty() ? 
            ($notes->where('is_resolved', true)->count() / $notes->count()) * 100 : 100;
        
        if ($resolutionRate < 60) {
            $recommendations[] = [
                'type' => 'resolution_rate',
                'priority' => 'medium',
                'message' => 'Low issue resolution rate. Improve follow-up processes.',
                'action' => 'Strengthen issue tracking and resolution workflows'
            ];
        }

        return $recommendations;
    }

    public function destroy(BedNote $bedNote): JsonResponse
    {
        try {
            // Only allow deletion by creator or admin, and only if not approved
            if ($bedNote->status === 'approved' && !Auth::user()->can('forceDelete', $bedNote)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete approved note without proper permissions',
                ], 403);
            }

            $bedNote->delete();

            Log::info('Bed note deleted', [
                'note_id' => $bedNote->id,
                'deleted_by' => Auth::id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Bed note deleted successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete bed note', [
                'note_id' => $bedNote->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete bed note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}