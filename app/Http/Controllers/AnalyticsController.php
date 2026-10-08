<?php

namespace App\Http\Controllers;

use App\Models\CropCycle;
use App\Models\Harvest;
use App\Models\HarvestSalesAllocation;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AnalyticsController extends Controller
{
    /**
     * Get overall farm metrics and KPIs
     */
    public function metrics(Request $request): JsonResponse
    {
        $timeRange = $request->get('timeRange', 'month');
        $startDate = $this->getStartDate($timeRange);
        
        try {
            $safeGet = function (callable $fn, $default = []) {
                try { return $fn(); } catch (\Exception $e) { return $default; }
            };

            $metrics = [
                'revenue'       => $safeGet(fn() => $this->getRevenueMetrics($startDate),       ['total' => 0, 'count' => 0, 'average' => 0, 'growth' => 0]),
                'expenses'      => $safeGet(fn() => $this->getExpenseMetrics($startDate),        ['total' => 0, 'count' => 0, 'average' => 0, 'by_category' => []]),
                'production'    => $safeGet(fn() => $this->getProductionMetrics($startDate),     ['total_yield' => 0, 'harvest_count' => 0, 'average_yield' => 0, 'marketable_percentage' => 85.0]),
                'efficiency'    => $safeGet(fn() => $this->getEfficiencyMetrics($startDate),     ['labour_efficiency' => 0, 'resource_utilization' => 0, 'cost_per_unit' => 0, 'yield_vs_expected' => 100]),
                'profitability' => $safeGet(fn() => $this->getProfitabilityMetrics($startDate),  ['gross_profit' => 0, 'profit_margin' => 0, 'roi' => 0, 'break_even_point' => ['days' => 0, 'amount' => 0]]),
                'resources'     => $safeGet(fn() => $this->getResourceMetrics($startDate),       ['harvest_count' => 0, 'total_yield' => 0, 'equipment_utilization' => 65.0]),
            ];

            return response()->json([
                'success' => true,
                'data' => $metrics,
                'timeRange' => $timeRange,
                'period' => [
                    'start' => $startDate->toDateString(),
                    'end' => now()->toDateString(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch metrics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get farm activity data for charts
     */
    public function activity(Request $request): JsonResponse
    {
        $timeRange = $request->get('timeRange', 'week');
        $startDate = $this->getStartDate($timeRange);
        
        try {
            $activities = [
                'harvests' => $this->getHarvestActivity($startDate, $timeRange),
                'expenses' => $this->getExpenseActivity($startDate, $timeRange),
                'labour' => $this->getLabourActivity($startDate, $timeRange),
                'sales' => $this->getSalesActivity($startDate, $timeRange),
            ];

            return response()->json([
                'success' => true,
                'data' => $activities,
                'timeRange' => $timeRange
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch activity data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get crop yield analytics
     */
    public function cropYields(Request $request): JsonResponse
    {
        $timeRange = $request->get('timeRange', 'month');
        $startDate = $this->getStartDate($timeRange);
        
        try {
            // Join with crop_cycles to get crop names
            $yields = DB::table('harvests as h')
                ->join('crop_cycles as cc', 'h.crop_cycle_id', '=', 'cc.id')
                ->select([
                    'cc.crop_name',
                    DB::raw('SUM(h.total_quantity) as total_yield'),
                    DB::raw('AVG(h.total_quantity) as average_yield'),
                    DB::raw('COUNT(*) as harvest_count')
                ])
                ->where('h.harvest_date', '>=', $startDate)
                ->where('h.status', 'approved')
                ->groupBy('cc.crop_name')
                ->orderByDesc('total_yield')
                ->get();

            $yieldTrends = $this->getYieldTrends($startDate, $timeRange);

            return response()->json([
                'success' => true,
                'data' => [
                    'yields' => $yields,
                    'trends' => $yieldTrends,
                    'summary' => [
                        'total_crops' => $yields->count(),
                        'total_yield' => $yields->sum('total_yield'),
                        'average_performance' => $yields->avg('average_yield'),
                        'best_performing' => $yields->first()?->crop_name ?? 'None',
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch crop yield data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get weather impact analytics
     */
    public function weather(Request $request): JsonResponse
    {
        $timeRange = $request->get('timeRange', 'month');
        $startDate = $this->getStartDate($timeRange);
        
        try {
            // Get weather-related field observations
            $weatherData = DB::table('field_observations')
                ->select([
                    'observation_date',
                    'weather_conditions',
                    'temperature',
                    'rainfall',
                    'humidity',
                    'wind_speed'
                ])
                ->where('observation_date', '>=', $startDate)
                ->whereNotNull('weather_conditions')
                ->orderBy('observation_date')
                ->get()
                ->groupBy(function($item) use ($timeRange) {
                    $date = Carbon::parse($item->observation_date);
                    return $timeRange === 'week' ? $date->format('l') : $date->format('M j');
                });

            $weatherImpact = $this->getWeatherImpactAnalysis($startDate);

            return response()->json([
                'success' => true,
                'data' => [
                    'daily_data' => $weatherData,
                    'impact_analysis' => $weatherImpact,
                    'summary' => [
                        'average_temp' => $weatherData->flatten()->avg('temperature'),
                        'total_rainfall' => $weatherData->flatten()->sum('rainfall'),
                        'average_humidity' => $weatherData->flatten()->avg('humidity'),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => true, // Return success with empty data for now
                'data' => [
                    'daily_data' => [],
                    'impact_analysis' => [],
                    'summary' => [
                        'average_temp' => 0,
                        'total_rainfall' => 0,
                        'average_humidity' => 0,
                    ]
                ],
                'message' => 'Weather data not available'
            ]);
        }
    }

    /**
     * Get trend analysis
     */
    public function trends(Request $request): JsonResponse
    {
        $period = $request->get('period', 'week');
        $startDate = $this->getStartDate($period);
        
        try {
            $trends = [
                'revenue' => $this->getRevenueTrends($startDate, $period),
                'expenses' => $this->getExpenseTrends($startDate, $period),
                'production' => $this->getProductionTrends($startDate, $period),
                'efficiency' => $this->getEfficiencyTrends($startDate, $period),
            ];

            return response()->json([
                'success' => true,
                'data' => $trends,
                'period' => $period
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch trends',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export analytics data
     */
    public function export(Request $request): JsonResponse
    {
        $format = $request->get('format', 'xlsx');
        $sections = $request->get('sections', ['metrics', 'yields']);
        $timeRange = $request->get('timeRange', 'month');
        
        try {
            $data = $this->prepareExportData($sections, $timeRange);
            $filename = $this->generateExportFile($data, $format);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'exportUrl' => Storage::url($filename),
                    'filename' => basename($filename),
                    'format' => $format,
                    'timestamp' => now()->toISOString()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Private helper methods

    private function getStartDate(string $timeRange): Carbon
    {
        return match($timeRange) {
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'quarter' => now()->startOfQuarter(),
            'year' => now()->startOfYear(),
            default => now()->startOfMonth(),
        };
    }

    private function getRevenueMetrics(Carbon $startDate): array
    {
        // Join with harvests and crop_cycles to filter by farm
        $farmId = auth()->user()->farms()->first()?->id;
        $salesData = HarvestSalesAllocation::join('harvests', 'harvest_sales_allocations.harvest_id', '=', 'harvests.id')
            ->join('crop_cycles', 'harvests.crop_cycle_id', '=', 'crop_cycles.id')
            ->where('crop_cycles.farm_id', $farmId)
            ->where('harvest_sales_allocations.allocation_date', '>=', $startDate)
            ->where('harvest_sales_allocations.status', 'delivered');
            
        return [
            'total' => $salesData->sum('harvest_sales_allocations.total_value') ?: 0,
            'count' => $salesData->count() ?: 0,
            'average' => $salesData->avg('harvest_sales_allocations.total_value') ?: 0,
            'growth' => $this->calculateGrowth('harvest_sales', $startDate),
        ];
    }

    private function getExpenseMetrics(Carbon $startDate): array
    {
        // Use transactions with type 'debit' as expenses
        $expenses = Transaction::where('transaction_date', '>=', $startDate)
            ->where('type', 'debit');
        
        return [
            'total' => $expenses->sum('amount') ?: 0,
            'count' => $expenses->count() ?: 0,
            'average' => $expenses->avg('amount') ?: 0,
            'by_category' => $expenses->select('category', DB::raw('SUM(amount) as total'))
                ->groupBy('category')
                ->orderByDesc('total')
                ->get(),
        ];
    }

    private function getProductionMetrics(Carbon $startDate): array
    {
        // Join with crop_cycles to filter by farm_id
        $harvests = Harvest::join('crop_cycles', 'harvests.crop_cycle_id', '=', 'crop_cycles.id')
            ->where('crop_cycles.farm_id', auth()->user()->farms()->first()?->id)
            ->where('harvests.harvest_date', '>=', $startDate)
            ->where('harvests.status', 'approved')
            ->select('harvests.*');
            
        return [
            'total_yield' => $harvests->sum('harvests.total_quantity') ?: 0,
            'harvest_count' => $harvests->count() ?: 0,
            'average_yield' => $harvests->avg('harvests.total_quantity') ?: 0,
            'marketable_percentage' => $this->getMarketablePercentage($harvests),
        ];
    }

    private function getEfficiencyMetrics(Carbon $startDate): array
    {
        return [
            'labour_efficiency' => $this->getLabourEfficiency($startDate),
            'resource_utilization' => $this->getResourceUtilization($startDate),
            'cost_per_unit' => $this->getCostPerUnit($startDate),
            'yield_vs_expected' => $this->getYieldVsExpected($startDate),
        ];
    }

    private function getProfitabilityMetrics(Carbon $startDate): array
    {
        $revenue = HarvestSalesAllocation::where('created_at', '>=', $startDate)
            ->sum('total_value') ?: 0;
        $expenses = Transaction::where('transaction_date', '>=', $startDate)
            ->where('type', 'debit')
            ->sum('amount') ?: 0;
        
        return [
            'gross_profit' => $revenue - $expenses,
            'profit_margin' => $revenue > 0 ? (($revenue - $expenses) / $revenue) * 100 : 0,
            'roi' => $expenses > 0 ? (($revenue - $expenses) / $expenses) * 100 : 0,
            'break_even_point' => $this->calculateBreakEvenPoint($startDate),
        ];
    }

    private function getResourceMetrics(Carbon $startDate): array
    {
        return [
            'harvest_count' => Harvest::where('harvest_date', '>=', $startDate)->count(),
            'total_yield' => Harvest::where('harvest_date', '>=', $startDate)->sum('total_quantity') ?: 0,
            'equipment_utilization' => $this->getEquipmentUtilization($startDate),
        ];
    }

    private function getHarvestActivity(Carbon $startDate, string $timeRange): array
    {
        return Harvest::join('crop_cycles', 'harvests.crop_cycle_id', '=', 'crop_cycles.id')
            ->where('crop_cycles.farm_id', auth()->user()->farms()->first()?->id)
            ->select([
                DB::raw($this->getDateGroupBy($timeRange, 'harvests.harvest_date') . ' as period'),
                DB::raw('SUM(harvests.total_quantity) as total_yield'),
                DB::raw('COUNT(*) as harvest_count')
            ])
            ->where('harvests.harvest_date', '>=', $startDate)
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->toArray();
    }

    private function getExpenseActivity(Carbon $startDate, string $timeRange): array
    {
        return Transaction::select([
                DB::raw($this->getTransactionDateGroupBy($timeRange) . ' as period'),
                DB::raw('SUM(amount) as total_amount'),
                DB::raw('COUNT(*) as expense_count')
            ])
            ->where('transaction_date', '>=', $startDate)
            ->where('type', 'debit')
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->toArray();
    }

    private function getLabourActivity(Carbon $startDate, string $timeRange): array
    {
        // Return empty array for now since labor entries table may not exist
        return [];
    }

    private function getSalesActivity(Carbon $startDate, string $timeRange): array
    {
        return HarvestSalesAllocation::select([
                DB::raw($this->getAllocationDateGroupBy($timeRange) . ' as period'),
                DB::raw('SUM(total_value) as total_sales'),
                DB::raw('COUNT(*) as sales_count')
            ])
            ->where('created_at', '>=', $startDate)
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->toArray();
    }

    private function getDateGroupBy(string $timeRange, string $dateColumn = 'harvest_date'): string
    {
        return match($timeRange) {
            'week' => "TO_CHAR({$dateColumn}, 'Day')",
            'month' => "DATE_PART('week', {$dateColumn})",
            'quarter' => "DATE_PART('month', {$dateColumn})",
            'year' => "DATE_PART('quarter', {$dateColumn})",
            default => "DATE({$dateColumn})",
        };
    }

    private function getTransactionDateGroupBy(string $timeRange): string
    {
        return match($timeRange) {
            'week' => 'TO_CHAR(transaction_date, \'Day\')',
            'month' => 'DATE_PART(\'week\', transaction_date)',
            'quarter' => 'DATE_PART(\'month\', transaction_date)',
            'year' => 'DATE_PART(\'quarter\', transaction_date)',
            default => 'DATE(transaction_date)',
        };
    }

    private function getAllocationDateGroupBy(string $timeRange): string
    {
        return match($timeRange) {
            'week' => 'TO_CHAR(created_at, \'Day\')',
            'month' => 'DATE_PART(\'week\', created_at)',
            'quarter' => 'DATE_PART(\'month\', created_at)',
            'year' => 'DATE_PART(\'quarter\', created_at)',
            default => 'DATE(created_at)',
        };
    }

    private function calculateGrowth(string $metric, Carbon $startDate): float
    {
        // Compare with previous period
        $currentPeriod = match($metric) {
            'harvest_sales' => HarvestSalesAllocation::where('created_at', '>=', $startDate)
                ->sum('total_value') ?: 0,
            default => 0,
        };
        
        $previousStart = $startDate->copy()->subDays($startDate->diffInDays(now()));
        $previousPeriod = match($metric) {
            'harvest_sales' => HarvestSalesAllocation::whereBetween('created_at', [$previousStart, $startDate])
                ->sum('total_value') ?: 0,
            default => 0,
        };
        
        return $previousPeriod > 0 ? (($currentPeriod - $previousPeriod) / $previousPeriod) * 100 : 0;
    }

    // Additional helper methods with safe defaults
    private function getMarketablePercentage($harvests): float
    {
        // Return default marketable percentage since column may not exist
        return 85.0; // Default 85% marketable
    }

    private function getLabourEfficiency(Carbon $startDate): float
    {
        // Return default value since labor table may not exist
        return 0.5; // Default efficiency
    }

    private function getResourceUtilization(Carbon $startDate): float
    {
        // Simplified calculation - can be made more sophisticated
        return 75.0; // Placeholder
    }

    private function getCostPerUnit(Carbon $startDate): float
    {
        $farmId = auth()->user()->farms()->first()?->id ?? auth()->user()->ownedFarms()->first()?->id;

        // Transactions table has no farm_id — use created_by_user_id scoping instead
        $totalExpenses = Transaction::where('created_by_user_id', auth()->id())
            ->where('transaction_date', '>=', $startDate)
            ->where('type', 'debit')
            ->sum('amount') ?: 0;

        $harvestQuery = Harvest::join('crop_cycles', 'harvests.crop_cycle_id', '=', 'crop_cycles.id')
            ->where('harvests.harvest_date', '>=', $startDate);
        if ($farmId) {
            $harvestQuery->where('crop_cycles.farm_id', $farmId);
        }
        $totalYield = $harvestQuery->sum('harvests.total_quantity') ?: 1;

        return $totalYield > 0 ? $totalExpenses / $totalYield : 0;
    }

    private function getYieldVsExpected(Carbon $startDate): float
    {
        $farmId = auth()->user()->farms()->first()?->id ?? auth()->user()->ownedFarms()->first()?->id;

        $harvestQuery = Harvest::join('crop_cycles', 'harvests.crop_cycle_id', '=', 'crop_cycles.id')
            ->where('harvests.harvest_date', '>=', $startDate);
        if ($farmId) {
            $harvestQuery->where('crop_cycles.farm_id', $farmId);
        }
        $actualYield   = (clone $harvestQuery)->sum('harvests.total_quantity');
        $expectedYield = (clone $harvestQuery)->sum('harvests.expected_quantity');

        return $expectedYield > 0 ? ($actualYield / $expectedYield) * 100 : 100;
    }

    private function calculateBreakEvenPoint(Carbon $startDate): array
    {
        return ['days' => 0, 'amount' => 0]; // Placeholder
    }

    private function getInventoryLevels(): array
    {
        // Return empty array since inventory table may have issues
        return [];
    }

    private function getEquipmentUtilization(Carbon $startDate): float
    {
        return 65.0; // Placeholder
    }

    private function getYieldTrends(Carbon $startDate, string $timeRange): array
    {
        return []; // Placeholder - implement based on needs
    }

    private function getWeatherImpactAnalysis(Carbon $startDate): array
    {
        return []; // Placeholder - implement when weather data is available
    }

    private function getRevenueTrends(Carbon $startDate, string $period): array
    {
        return HarvestSalesAllocation::select([
                DB::raw($this->getAllocationDateGroupBy($period) . ' as period'),
                DB::raw('SUM(total_value) as value')
            ])
            ->where('created_at', '>=', $startDate)
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->toArray();
    }

    private function getExpenseTrends(Carbon $startDate, string $period): array
    {
        return Transaction::select([
                DB::raw($this->getTransactionDateGroupBy($period) . ' as period'),
                DB::raw('SUM(amount) as value')
            ])
            ->where('transaction_date', '>=', $startDate)
            ->where('type', 'debit')
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->toArray();
    }

    private function getProductionTrends(Carbon $startDate, string $period): array
    {
        return Harvest::select([
                DB::raw($this->getDateGroupBy($period) . ' as period'),
                DB::raw('SUM(total_quantity) as value')
            ])
            ->where('harvest_date', '>=', $startDate)
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->toArray();
    }

    private function getEfficiencyTrends(Carbon $startDate, string $period): array
    {
        // Return harvest efficiency trends based on variance percentage
        return Harvest::select([
                DB::raw($this->getDateGroupBy($period) . ' as period'),
                DB::raw('AVG(variance_percentage) as value')
            ])
            ->where('harvest_date', '>=', $startDate)
            ->where('status', 'approved')
            ->whereNotNull('variance_percentage')
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->toArray();
    }

    private function prepareExportData(array $sections, string $timeRange): array
    {
        $startDate = $this->getStartDate($timeRange);
        $data = [];

        if (in_array('metrics', $sections)) {
            $data['metrics'] = [
                'revenue' => $this->getRevenueMetrics($startDate),
                'expenses' => $this->getExpenseMetrics($startDate),
                'production' => $this->getProductionMetrics($startDate),
                'efficiency' => $this->getEfficiencyMetrics($startDate),
                'profitability' => $this->getProfitabilityMetrics($startDate),
            ];
        }

        if (in_array('yields', $sections)) {
            $yields = DB::table('harvests as h')
                ->join('crop_cycles as cc', 'h.crop_cycle_id', '=', 'cc.id')
                ->select([
                    'cc.crop_name',
                    'h.harvest_date',
                    'h.total_quantity',
                    'h.quality_grade',
                    'h.variance_percentage'
                ])
                ->where('h.harvest_date', '>=', $startDate)
                ->where('h.status', 'approved')
                ->orderBy('h.harvest_date')
                ->get();
            $data['yields'] = $yields->toArray();
        }

        if (in_array('activity', $sections)) {
            $data['activity'] = [
                'harvests' => $this->getHarvestActivity($startDate, $timeRange),
                'expenses' => $this->getExpenseActivity($startDate, $timeRange),
                'sales' => $this->getSalesActivity($startDate, $timeRange),
            ];
        }

        if (in_array('financial', $sections)) {
            $data['financial'] = [
                'revenue_trends' => $this->getRevenueTrends($startDate, $timeRange),
                'expense_trends' => $this->getExpenseTrends($startDate, $timeRange),
                'profit_analysis' => $this->getProfitabilityMetrics($startDate),
            ];
        }

        return $data;
    }

    private function generateExportFile(array $data, string $format): string
    {
        $timestamp = now()->format('Y-m-d_H-i-s');
        $filename = "analytics_export_{$timestamp}";

        switch ($format) {
            case 'csv':
                return $this->generateCSV($data, $filename);
            case 'xlsx':
                return $this->generateExcel($data, $filename);
            case 'pdf':
                return $this->generatePDF($data, $filename);
            default:
                return $this->generateJSON($data, $filename);
        }
    }

    private function generateCSV(array $data, string $filename): string
    {
        $filepath = "exports/{$filename}.csv";
        $content = "Section,Metric,Value,Date\n";

        foreach ($data as $section => $sectionData) {
            if (is_array($sectionData)) {
                foreach ($sectionData as $key => $value) {
                    if (is_numeric($value)) {
                        $content .= "\"{$section}\",\"{$key}\",\"{$value}\",\"" . now()->toDateString() . "\"\n";
                    }
                }
            }
        }

        Storage::put($filepath, $content);
        return $filepath;
    }

    private function generateExcel(array $data, string $filename): string
    {
        // For now, return CSV format - can be enhanced with PhpSpreadsheet
        return $this->generateCSV($data, $filename);
    }

    private function generatePDF(array $data, string $filename): string
    {
        // For now, return JSON format - can be enhanced with PDF library
        return $this->generateJSON($data, $filename);
    }

    private function generateJSON(array $data, string $filename): string
    {
        $filepath = "exports/{$filename}.json";
        $content = json_encode($data, JSON_PRETTY_PRINT);
        Storage::put($filepath, $content);
        return $filepath;
    }
}