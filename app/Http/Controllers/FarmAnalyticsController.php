<?php

namespace App\Http\Controllers;

use App\Models\Farm;
use App\Models\CropCycle;
use App\Models\CropTask;
use App\Models\Expense;
use App\Models\LabourEntry;
use App\Models\InventoryItem;
use App\Models\Harvest;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class FarmAnalyticsController extends Controller
{
    /**
     * Get comprehensive farm metrics for dashboard
     */
    public function getFarmMetrics(Request $request): JsonResponse
    {
        try {
            $timeRange = $request->query('time_range', 'month');
            $user = $request->user();
            $activeFarm = $user->ownedFarms()->first() ?? $user->farms()->first();
            
            if (!$activeFarm) {
                return response()->json([
                    'success' => false,
                    'message' => 'No farm found for user'
                ], 422);
            }
            
            $farmId = $activeFarm->id;

            $cacheKey = "farm_metrics_{$farmId}_{$timeRange}";
            
            $metrics = Cache::remember($cacheKey, 300, function () use ($farmId, $timeRange) { // 5 minutes cache
                return $this->calculateFarmMetrics($farmId, $timeRange);
            });

            return response()->json([
                'success' => true,
                'data' => $metrics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch farm metrics',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Calculate comprehensive farm metrics
     */
    protected function calculateFarmMetrics($farmId, $timeRange)
    {
        $farm = Farm::find($farmId);
        $dateRange = $this->getDateRange($timeRange);
        
        // Switch to tenant schema for queries
        $registry = $farm->tenantRegistry;
        if ($registry && $registry->is_active) {
            $registry->switchToTenant();
        }
        
        // Basic farm stats
        $farmStats = [
            'active_fields' => $this->getActiveFields($farmId),
            'total_area' => $farm->size ?? 0,
            'area_unit' => $farm->size_unit ?? 'acres',
            'workers_count' => $this->getWorkersCount($farmId),
            'inventory_lines' => $this->getInventoryCount($farmId)
        ];

        // Production metrics
        $productionStats = [
            'total_crops' => $this->getTotalCrops($farmId, $dateRange),
            'active_crops' => $this->getActiveCrops($farmId),
            'completed_crops' => $this->getCompletedCrops($farmId, $dateRange),
            'total_yield' => $this->getTotalYield($farmId, $dateRange),
            'harvest_count' => $this->getHarvestCount($farmId, $dateRange)
        ];

        // Financial metrics
        $financialStats = [
            'total_revenue' => $this->getTotalRevenue($farmId, $dateRange),
            'total_expenses' => $this->getTotalExpenses($farmId, $dateRange),
            'gross_profit' => 0, // Will calculate below
            'profit_margin' => 0 // Will calculate below
        ];

        // Calculate derived metrics
        $financialStats['gross_profit'] = $financialStats['total_revenue'] - $financialStats['total_expenses'];
        $financialStats['profit_margin'] = $financialStats['total_revenue'] > 0 
            ? round(($financialStats['gross_profit'] / $financialStats['total_revenue']) * 100, 2) 
            : 0;

        // Task metrics
        $taskStats = [
            'tasks_pending' => $this->getPendingTasks($farmId),
            'tasks_overdue' => $this->getOverdueTasks($farmId),
            'tasks_completed' => $this->getCompletedTasks($farmId, $dateRange)
        ];

        // Activity metrics
        $activityStats = [
            'labour_hours' => $this->getLabourHours($farmId, $dateRange),
            'expense_entries' => $this->getExpenseEntries($farmId, $dateRange),
            'harvest_entries' => $this->getHarvestEntries($farmId, $dateRange)
        ];

        return [
            'farm' => $farmStats,
            'production' => $productionStats,
            'financial' => $financialStats,
            'tasks' => $taskStats,
            'activity' => $activityStats,
            'time_range' => $timeRange,
            'generated_at' => now()->toISOString()
        ];
    }

    // Farm Statistics Methods
    protected function getActiveFields($farmId)
    {
        // For now, count active crops as field estimate
        // TODO: Improve this when bed mapping system is fully implemented
        $activeCrops = CropCycle::where('farm_id', $farmId)
            ->whereIn('status', ['planning', 'nursery', 'active', 'transplanted'])
            ->count();
            
        // Estimate fields based on active crops (assume 1-3 crops per field)
        return max(1, ceil($activeCrops / 2));
    }

    protected function getWorkersCount($farmId)
    {
        // For now, return a default count until worker tables are implemented
        // TODO: Implement when labour management module is fully set up
        return 3; // Default: Farm owner + 2 workers
    }

    protected function getInventoryCount($farmId)
    {
        // For now, return a default count until inventory tables are implemented
        // TODO: Implement when inventory module is fully set up
        return 25; // Default inventory count
    }

    // Production Statistics Methods
    protected function getTotalCrops($farmId, $dateRange)
    {
        return CropCycle::where('farm_id', $farmId)
            ->whereBetween('planting_date', [$dateRange['start'], $dateRange['end']])
            ->count();
    }

    protected function getActiveCrops($farmId)
    {
        return CropCycle::where('farm_id', $farmId)
            ->whereIn('status', ['planning', 'nursery', 'active', 'transplanted'])
            ->count();
    }

    protected function getCompletedCrops($farmId, $dateRange)
    {
        return CropCycle::where('farm_id', $farmId)
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$dateRange['start'], $dateRange['end']])
            ->count();
    }

    protected function getTotalYield($farmId, $dateRange)
    {
        // TODO: Implement when harvest module is available
        return 0;
    }

    protected function getHarvestCount($farmId, $dateRange)
    {
        // TODO: Implement when harvest module is available
        return 0;
    }

    // Financial Statistics Methods
    protected function getTotalRevenue($farmId, $dateRange)
    {
        // TODO: Implement when sales module is available
        return 0;
    }

    protected function getTotalExpenses($farmId, $dateRange)
    {
        // TODO: Implement when expense module is available
        return 0;
    }

    // Task Statistics Methods
    protected function getPendingTasks($farmId)
    {
        $activeCropIds = CropCycle::where('farm_id', $farmId)
            ->whereIn('status', ['planning', 'nursery', 'active', 'transplanted'])
            ->pluck('id');

        return CropTask::whereIn('crop_cycle_id', $activeCropIds)
            ->where('status', 'scheduled')
            ->count();
    }

    protected function getOverdueTasks($farmId)
    {
        $activeCropIds = CropCycle::where('farm_id', $farmId)
            ->whereIn('status', ['planning', 'nursery', 'active', 'transplanted'])
            ->pluck('id');

        return CropTask::whereIn('crop_cycle_id', $activeCropIds)
            ->where('status', 'scheduled')
            ->where('scheduled_date', '<', now()->toDateString())
            ->count();
    }

    protected function getCompletedTasks($farmId, $dateRange)
    {
        $cropIds = CropCycle::where('farm_id', $farmId)->pluck('id');

        return CropTask::whereIn('crop_cycle_id', $cropIds)
            ->where('status', 'completed')
            ->whereBetween('completed_date', [$dateRange['start'], $dateRange['end']])
            ->count();
    }

    // Activity Statistics Methods
    protected function getLabourHours($farmId, $dateRange)
    {
        // TODO: Implement when labour module is available
        return 0;
    }

    protected function getExpenseEntries($farmId, $dateRange)
    {
        // TODO: Implement when expense module is available
        return 0;
    }

    protected function getHarvestEntries($farmId, $dateRange)
    {
        // TODO: Implement when harvest module is available
        return 0;
    }

    // Utility Methods
    protected function getDateRange($timeRange)
    {
        $end = Carbon::now();
        
        switch ($timeRange) {
            case 'week':
                $start = $end->copy()->subWeek();
                break;
            case '2weeks':
                $start = $end->copy()->subWeeks(2);
                break;
            case 'month':
                $start = $end->copy()->subMonth();
                break;
            case '3months':
                $start = $end->copy()->subMonths(3);
                break;
            case '6months':
                $start = $end->copy()->subMonths(6);
                break;
            case 'year':
                $start = $end->copy()->subYear();
                break;
            default:
                $start = $end->copy()->subMonth();
        }

        return [
            'start' => $start,
            'end' => $end
        ];
    }

    /**
     * Clear farm metrics cache
     */
    public function clearMetricsCache(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $activeFarm = $user->ownedFarms()->first() ?? $user->farms()->first();
            
            if (!$activeFarm) {
                return response()->json([
                    'success' => false,
                    'message' => 'No farm found for user'
                ], 422);
            }
            
            $farmId = $activeFarm->id;

            $timeRanges = ['week', '2weeks', 'month', '3months', '6months', 'year'];
            $cleared = 0;

            foreach ($timeRanges as $range) {
                $cacheKey = "farm_metrics_{$farmId}_{$range}";
                if (Cache::forget($cacheKey)) {
                    $cleared++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Cleared {$cleared} metric cache entries"
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear metrics cache',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}