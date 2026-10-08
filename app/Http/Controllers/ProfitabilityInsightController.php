<?php

namespace App\Http\Controllers;

use App\Models\ProfitabilityInsight;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ProfitabilityInsightController extends Controller
{
    public function __construct()
    {
        $this->middleware(function (Request $request, Closure $next) {
            $farmId = (string) $request->header('X-Tenant-ID');

            return $farmId !== '' && $request->user()?->getRoleOnFarm($farmId) === 'owner'
                ? $next($request)
                : response()->json(['status' => 'error', 'message' => 'Profitability data is restricted to the farm owner'], 403);
        });
    }

    public function index(Request $request): JsonResponse
    {
        $farmId = (string) $request->header('X-Tenant-ID');
        
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['new', 'viewed', 'acknowledged', 'acted_upon', 'dismissed'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'insight_type' => ['nullable', Rule::in([
                'cost_alert', 'yield_performance', 'profit_warning', 'efficiency_tip',
                'market_opportunity', 'seasonal_comparison', 'resource_optimization',
                'price_variance', 'performance_benchmark'
            ])],
            'category' => ['nullable', Rule::in([
                'cost_management', 'yield_optimization', 'revenue_enhancement',
                'efficiency_improvement', 'risk_mitigation', 'market_intelligence'
            ])],
            'actionable_only' => 'nullable|boolean',
            'overdue_only' => 'nullable|boolean',
            'crop_cycle_id' => 'nullable|uuid|exists:crop_cycles,id',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $query = ProfitabilityInsight::forFarm($farmId);

        // Apply filters
        if (isset($validated['status'])) {
            $query->byStatus($validated['status']);
        }

        if (isset($validated['priority'])) {
            $query->byPriority($validated['priority']);
        }

        if (isset($validated['insight_type'])) {
            $query->byType($validated['insight_type']);
        }

        if (isset($validated['category'])) {
            $query->byCategory($validated['category']);
        }

        if ($validated['actionable_only'] ?? false) {
            $query->actionable();
        }

        if ($validated['overdue_only'] ?? false) {
            $query->overdue();
        }

        if (isset($validated['crop_cycle_id'])) {
            $query->forCropCycle($validated['crop_cycle_id']);
        }

        // Include relationships
        $query->with(['cropCycle:id,crop_name,start_date']);

        // Sorting
        $query->orderBy('priority', 'desc')
              ->orderBy('created_at', 'desc');

        // Pagination
        $perPage = min($validated['per_page'] ?? 15, 100);
        $insights = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $insights,
            'meta' => [
                'filters_applied' => array_filter($validated),
                'summary' => [
                    'total_unread' => ProfitabilityInsight::forFarm($farmId)->unread()->count(),
                    'total_high_priority' => ProfitabilityInsight::forFarm($farmId)->highPriority()->count(),
                    'total_overdue' => ProfitabilityInsight::forFarm($farmId)->overdue()->count(),
                    'total_actionable' => ProfitabilityInsight::forFarm($farmId)->actionable()->count()
                ]
            ]
        ]);
    }

    public function show(string $id): JsonResponse
    {
        try {
            $insight = ProfitabilityInsight::with([
                'farm:id,name',
                'cropCycle:id,crop_name,start_date,expected_harvest_date'
            ])->findOrFail($id);

            // Mark as viewed if it's new
            if ($insight->status === 'new') {
                $insight->markAsViewed();
            }

            // Get related insights
            $relatedInsights = ProfitabilityInsight::where('farm_id', $insight->farm_id)
                ->where('id', '!=', $id)
                ->where(function ($query) use ($insight) {
                    $query->where('insight_type', $insight->insight_type)
                          ->orWhere('category', $insight->category);
                })
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'insight_type', 'title', 'priority', 'created_at']);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'insight' => $insight,
                    'related_insights' => $relatedInsights,
                    'impact_description' => $insight->getImpactDescription(),
                    'is_overdue' => $insight->isOverdue(),
                    'is_high_priority' => $insight->isHighPriority()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Insight not found'
            ], 404);
        }
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        try {
            $insight = ProfitabilityInsight::findOrFail($id);
            
            $validated = $request->validate([
                'notes' => 'nullable|string|max:1000'
            ]);

            $insight->acknowledge($validated['notes'] ?? null);

            return response()->json([
                'status' => 'success',
                'message' => 'Insight acknowledged successfully',
                'data' => $insight->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to acknowledge insight: ' . $e->getMessage()
            ], 500);
        }
    }

    public function markAsActedUpon(Request $request, string $id): JsonResponse
    {
        try {
            $insight = ProfitabilityInsight::findOrFail($id);
            
            $validated = $request->validate([
                'action_notes' => 'required|string|max:1000'
            ]);

            $insight->markAsActedUpon($validated['action_notes']);

            return response()->json([
                'status' => 'success',
                'message' => 'Insight marked as acted upon successfully',
                'data' => $insight->fresh()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update insight status: ' . $e->getMessage()
            ], 500);
        }
    }

    public function dismiss(string $id): JsonResponse
    {
        try {
            $insight = ProfitabilityInsight::findOrFail($id);
            $insight->dismiss();

            return response()->json([
                'status' => 'success',
                'message' => 'Insight dismissed successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to dismiss insight: ' . $e->getMessage()
            ], 500);
        }
    }

    public function bulkAction(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'insight_ids' => 'required|array|min:1|max:50',
                'insight_ids.*' => 'uuid|exists:profitability_insights,id',
                'action' => ['required', Rule::in(['acknowledge', 'dismiss', 'mark_viewed'])],
                'notes' => 'nullable|string|max:1000'
            ]);

            $insights = ProfitabilityInsight::whereIn('id', $validated['insight_ids'])->get();
            $updated = 0;

            foreach ($insights as $insight) {
                switch ($validated['action']) {
                    case 'acknowledge':
                        if ($insight->status !== 'acted_upon') {
                            $insight->acknowledge($validated['notes'] ?? null);
                            $updated++;
                        }
                        break;
                    case 'dismiss':
                        if ($insight->status !== 'acted_upon') {
                            $insight->dismiss();
                            $updated++;
                        }
                        break;
                    case 'mark_viewed':
                        if ($insight->status === 'new') {
                            $insight->markAsViewed();
                            $updated++;
                        }
                        break;
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => "Bulk action completed successfully. {$updated} insights updated.",
                'data' => [
                    'total_processed' => count($insights),
                    'total_updated' => $updated,
                    'action' => $validated['action']
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to perform bulk action: ' . $e->getMessage()
            ], 500);
        }
    }

    public function generateInsights(Request $request): JsonResponse
    {
        try {
            $farmId = (string) $request->header('X-Tenant-ID');
            
            $validated = $request->validate([
                'crop_cycle_id' => 'nullable|uuid|exists:crop_cycles,id',
                'force_regenerate' => 'nullable|boolean'
            ]);

            // Get recent profitability snapshots
            $query = \App\Models\ProfitabilitySnapshot::forFarm($farmId);
            
            if (isset($validated['crop_cycle_id'])) {
                $query->forCropCycle($validated['crop_cycle_id']);
            } else {
                // Get last 30 days of data
                $query->where('calculated_at', '>=', now()->subDays(30));
            }

            $snapshots = $query->get();
            
            if ($snapshots->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No profitability data available for insight generation'
                ], 400);
            }

            $generatedCount = 0;

            foreach ($snapshots as $snapshot) {
                // Check if insights already exist for this snapshot
                $existingInsights = ProfitabilityInsight::where('crop_cycle_id', $snapshot->crop_cycle_id)
                    ->where('created_at', '>=', $snapshot->calculated_at->subHours(1))
                    ->count();

                if ($existingInsights > 0 && !($validated['force_regenerate'] ?? false)) {
                    continue; // Skip if insights already exist and not forcing regeneration
                }

                // Generate cost alerts
                if ($snapshot->labour_cost_percentage > 40) {
                    ProfitabilityInsight::createCostAlert(
                        $farmId,
                        $snapshot->crop_cycle_id,
                        'Labour',
                        $snapshot->labour_cost_percentage,
                        40
                    );
                    $generatedCount++;
                }

                // Generate yield performance insights
                if ($snapshot->yield_efficiency < 85) {
                    ProfitabilityInsight::createYieldPerformanceInsight(
                        $farmId,
                        $snapshot->crop_cycle_id,
                        $snapshot->yield_efficiency,
                        $snapshot->expected_yield,
                        $snapshot->actual_yield
                    );
                    $generatedCount++;
                }

                // Generate profit warnings
                if ($snapshot->profit_margin < 20 || $snapshot->net_profit < 0) {
                    ProfitabilityInsight::createProfitWarning(
                        $farmId,
                        $snapshot->crop_cycle_id,
                        $snapshot->profit_margin,
                        $snapshot->net_profit
                    );
                    $generatedCount++;
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => "Generated {$generatedCount} new insights",
                'data' => [
                    'insights_generated' => $generatedCount,
                    'snapshots_analyzed' => $snapshots->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate insights: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getInsightSummary(Request $request): JsonResponse
    {
        try {
            $farmId = (string) $request->header('X-Tenant-ID');
            
            $summary = [
                'total_insights' => ProfitabilityInsight::forFarm($farmId)->count(),
                'unread_insights' => ProfitabilityInsight::forFarm($farmId)->unread()->count(),
                'high_priority_insights' => ProfitabilityInsight::forFarm($farmId)->highPriority()->count(),
                'overdue_insights' => ProfitabilityInsight::forFarm($farmId)->overdue()->count(),
                'actionable_insights' => ProfitabilityInsight::forFarm($farmId)->actionable()->count(),
                'recent_insights' => ProfitabilityInsight::forFarm($farmId)->recent(7)->count(),
                'by_status' => ProfitabilityInsight::forFarm($farmId)
                    ->selectRaw('status, COUNT(*) as count')
                    ->groupBy('status')
                    ->pluck('count', 'status'),
                'by_priority' => ProfitabilityInsight::forFarm($farmId)
                    ->selectRaw('priority, COUNT(*) as count')
                    ->groupBy('priority')
                    ->pluck('count', 'priority'),
                'by_category' => ProfitabilityInsight::forFarm($farmId)
                    ->selectRaw('category, COUNT(*) as count')
                    ->groupBy('category')
                    ->pluck('count', 'category'),
                'by_type' => ProfitabilityInsight::forFarm($farmId)
                    ->selectRaw('insight_type, COUNT(*) as count')
                    ->groupBy('insight_type')
                    ->pluck('count', 'insight_type')
            ];

            // Calculate potential impact
            $potentialImpact = ProfitabilityInsight::forFarm($farmId)
                ->whereNotNull('potential_impact_amount')
                ->where('status', '!=', 'acted_upon')
                ->sum('potential_impact_amount');

            $summary['potential_total_impact'] = $potentialImpact;

            // Get top priority insights
            $summary['top_priority_insights'] = ProfitabilityInsight::forFarm($farmId)
                ->highPriority()
                ->unread()
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(['id', 'insight_type', 'title', 'priority', 'potential_impact_amount', 'created_at']);

            return response()->json([
                'status' => 'success',
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve insight summary: ' . $e->getMessage()
            ], 500);
        }
    }
}
