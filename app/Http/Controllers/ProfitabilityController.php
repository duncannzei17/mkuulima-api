<?php

namespace App\Http\Controllers;

use App\Models\CropCycle;
use App\Models\Expense;
use App\Models\Harvest;
use App\Models\ProfitabilitySnapshot;
use App\Models\Sale;
use Closure;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfitabilityController extends Controller
{
    public function __construct()
    {
        $this->middleware(function (Request $request, Closure $next) {
            $farmId = $this->farmId($request);
            $role = $farmId ? $request->user()?->getRoleOnFarm($farmId) : null;

            return $role === 'owner'
                ? $next($request)
                : response()->json(['status' => 'error', 'message' => 'Profitability data is restricted to the farm owner'], 403);
        });
    }

    public function dashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'season_id' => 'nullable|uuid|exists:crop_cycles,id',
            'include_completed' => 'nullable|boolean',
        ]);
        $farmId = $this->farmId($request);
        $query = CropCycle::where('farm_id', $farmId)->orderByDesc('start_date');
        if (!empty($validated['season_id'])) {
            $query->whereKey($validated['season_id']);
        }

        $cycles = $query->get();
        $snapshots = $cycles->map(fn (CropCycle $cycle) => ProfitabilitySnapshot::calculateForCropCycle($cycle->id));

        return response()->json([
            'status' => 'success',
            'data' => $this->dashboardPayload($farmId, $cycles, $snapshots),
        ]);
    }

    public function getFarmSummary(Request $request): JsonResponse
    {
        return $this->dashboard($request);
    }

    public function getCropProfitability(Request $request, string $id): JsonResponse
    {
        $cropCycle = CropCycle::where('farm_id', $this->farmId($request))->findOrFail($id);
        $snapshot = ProfitabilitySnapshot::calculateForCropCycle($cropCycle->id);

        return response()->json([
            'status' => 'success',
            'data' => $this->cropPayload($cropCycle, $snapshot),
        ]);
    }

    public function refreshProfitability(Request $request, string $cropCycleId): JsonResponse
    {
        $cycle = CropCycle::where('farm_id', $this->farmId($request))->findOrFail($cropCycleId);
        $snapshot = ProfitabilitySnapshot::calculateForCropCycle($cycle->id);

        return response()->json([
            'status' => 'success',
            'message' => 'Profitability recalculated successfully',
            'data' => $this->snapshotPayload($snapshot),
        ]);
    }

    public function getCropComparison(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_cycle_ids' => 'required|array|min:2|max:10',
            'crop_cycle_ids.*' => 'uuid|exists:crop_cycles,id',
        ]);
        $cycles = CropCycle::where('farm_id', $this->farmId($request))
            ->whereKey($validated['crop_cycle_ids'])
            ->get();
        if ($cycles->count() !== count($validated['crop_cycle_ids'])) {
            return response()->json(['status' => 'error', 'message' => 'One or more crop cycles are outside the active farm'], 422);
        }

        $comparison = $cycles->map(function (CropCycle $cycle) {
            $snapshot = ProfitabilitySnapshot::calculateForCropCycle($cycle->id);
            return $this->topCropPayload($cycle, $snapshot);
        })->sortByDesc('profit')->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'comparison' => $comparison,
                'best_performing' => $comparison->first(),
                'worst_performing' => $comparison->last(),
            ],
        ]);
    }

    public function getCostBreakdown(Request $request, string $cropCycleId): JsonResponse
    {
        $cycle = CropCycle::where('farm_id', $this->farmId($request))->findOrFail($cropCycleId);
        $snapshot = ProfitabilitySnapshot::calculateForCropCycle($cycle->id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_cost' => (float) $snapshot->cost_of_production,
                'categories' => $this->costDistribution(collect([$snapshot])),
                'insights' => $this->snapshotInsights($snapshot, $cycle),
            ],
        ]);
    }

    public function getTrends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'metric' => 'nullable|in:net_profit,profit_margin,roi_percentage,yield_efficiency,cost_per_kg',
        ]);
        $metric = $validated['metric'] ?? 'net_profit';
        $snapshots = ProfitabilitySnapshot::forFarm($this->farmId($request))
            ->with('cropCycle:id,crop_name,season_name')
            ->orderBy('calculated_at')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'metric' => $metric,
                'trends' => $snapshots->map(fn ($snapshot) => [
                    'period' => $snapshot->calculated_at->toDateString(),
                    'label' => $snapshot->cropCycle?->season_name ?: $snapshot->cropCycle?->crop_name,
                    'value' => round((float) $snapshot->{$metric}, 2),
                ])->values(),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'format' => 'nullable|in:csv,pdf',
            'season_id' => 'nullable|uuid|exists:crop_cycles,id',
        ]);
        $format = $validated['format'] ?? 'csv';
        $query = CropCycle::where('farm_id', $this->farmId($request))->orderBy('crop_name');
        if (!empty($validated['season_id'])) {
            $query->whereKey($validated['season_id']);
        }
        $rows = $query->get()->map(function (CropCycle $cycle) {
            return ['cycle' => $cycle, 'snapshot' => ProfitabilitySnapshot::calculateForCropCycle($cycle->id)];
        });
        $filename = 'profitability-' . now()->format('Y-m-d-His') . '.' . $format;

        if ($format === 'pdf') {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('exports.profitability', [
                'rows' => $rows,
                'generatedAt' => now(),
            ])->render());
            $pdf->setPaper('a4', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['Crop', 'Season', 'Revenue', 'Production Cost', 'Net Profit', 'ROI %', 'Yield Efficiency %', 'Cost/Kg', 'Profit/Kg']);
        foreach ($rows as $row) {
            $cycle = $row['cycle'];
            $snapshot = $row['snapshot'];
            fputcsv($stream, [
                $cycle->crop_name,
                $cycle->season_name,
                $snapshot->total_revenue,
                $snapshot->cost_of_production,
                $snapshot->net_profit,
                $snapshot->roi_percentage,
                $snapshot->yield_efficiency,
                $snapshot->cost_per_kg,
                $snapshot->profit_per_kg,
            ]);
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response("\xEF\xBB\xBF" . $csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function dashboardPayload(string $farmId, $cycles, $snapshots): array
    {
        $totalRevenue = (float) $snapshots->sum('total_revenue');
        $totalCosts = (float) $snapshots->sum('cost_of_production');
        $netProfit = (float) $snapshots->sum('net_profit');
        $actualYield = (float) $snapshots->sum('actual_yield');
        $averageYield = $snapshots->count() ? (float) $snapshots->avg('yield_efficiency') : 0;
        $averageRoi = $snapshots->count() ? (float) $snapshots->avg('roi_percentage') : 0;
        $healthScore = $snapshots->count() ? (float) $snapshots->avg('season_health_score') : 0;
        $topCrops = $cycles->map(function (CropCycle $cycle) use ($snapshots) {
            $snapshot = $snapshots->firstWhere('crop_cycle_id', $cycle->id);
            return $this->topCropPayload($cycle, $snapshot);
        })->sortByDesc('profit')->values()->map(function ($crop, $index) {
            $crop['rank'] = $index + 1;
            return $crop;
        });

        return [
            'farmHealth' => [
                'score' => round($healthScore, 2),
                'grade' => $this->grade($healthScore),
                'status' => $this->healthStatus($healthScore),
                'totalProfit' => round($netProfit, 2),
                'yieldEfficiency' => round($averageYield, 2),
                'roi' => round($averageRoi, 2),
                'trend' => 'stable',
                'lastUpdated' => optional($snapshots->max('calculated_at'))->toISOString() ?: now()->toISOString(),
                'factors' => [
                    'profitability' => round(max(0, min(100, $averageRoi)), 2),
                    'efficiency' => round($averageYield, 2),
                    'consistency' => round($healthScore, 2),
                    'growth' => 0,
                ],
            ],
            'kpis' => [
                'totalRevenue' => round($totalRevenue, 2),
                'revenueGrowth' => 0,
                'netProfit' => round($netProfit, 2),
                'profitMargin' => $totalRevenue > 0 ? round(($netProfit / $totalRevenue) * 100, 2) : 0,
                'costOfProduction' => round($totalCosts, 2),
                'costPerKg' => $actualYield > 0 ? round($totalCosts / $actualYield, 2) : 0,
                'roi' => $totalCosts > 0 ? round(($netProfit / $totalCosts) * 100, 2) : 0,
                'yieldEfficiency' => round($averageYield, 2),
            ],
            'snapshots' => $snapshots->map(fn ($snapshot) => $this->snapshotPayload($snapshot))->values(),
            'topCrops' => $topCrops,
            'crops' => $topCrops,
            'availableCrops' => $cycles->map(fn ($cycle) => ['id' => $cycle->id, 'name' => $cycle->crop_name])->values(),
            'seasons' => $cycles->map(function ($cycle) use ($snapshots) {
                $snapshot = $snapshots->firstWhere('crop_cycle_id', $cycle->id);
                return [
                    'id' => $cycle->id,
                    'name' => $cycle->season_name ?: $cycle->crop_name . ' ' . optional($cycle->start_date)->format('Y'),
                    'startDate' => optional($cycle->start_date)->toDateString(),
                    'endDate' => optional($cycle->completed_at ?: $cycle->expected_harvest_date)->toDateString(),
                    'status' => in_array($cycle->season_status, ['completed', 'archived'], true) ? 'completed' : 'active',
                    'totalRevenue' => (float) ($snapshot?->total_revenue ?? 0),
                    'totalProfit' => (float) ($snapshot?->net_profit ?? 0),
                    'cropCount' => 1,
                    'healthScore' => (float) ($snapshot?->season_health_score ?? 0),
                ];
            })->values(),
            'costDistribution' => $this->costDistribution($snapshots),
            'performance' => [
                'yieldEfficiency' => round($averageYield, 2),
                'costEfficiency' => $snapshots->count() ? round((float) $snapshots->avg('cost_efficiency_score'), 2) : 0,
                'revenueGrowth' => 0,
                'profitConsistency' => $snapshots->count() > 1 ? 75 : ($snapshots->count() ? 100 : 0),
                'overallScore' => round($healthScore, 2),
            ],
            'insights' => $cycles->flatMap(function ($cycle) use ($snapshots) {
                $snapshot = $snapshots->firstWhere('crop_cycle_id', $cycle->id);
                return $snapshot ? $this->snapshotInsights($snapshot, $cycle) : [];
            })->values(),
            'recentActivity' => $this->recentActivity($farmId),
            'benchmark' => $this->benchmarkPayload($averageRoi, $averageYield, $totalCosts, $actualYield),
        ];
    }

    private function cropPayload(CropCycle $cycle, ProfitabilitySnapshot $snapshot): array
    {
        return [
            'id' => $cycle->id,
            'name' => $cycle->crop_name,
            'grade' => $this->grade((float) $snapshot->season_health_score),
            'totalProfit' => (float) $snapshot->net_profit,
            'profitTrend' => 0,
            'roi' => (float) $snapshot->roi_percentage,
            'roiBenchmark' => 20,
            'profitPerKg' => (float) $snapshot->profit_per_kg,
            'totalYield' => (float) $snapshot->actual_yield,
            'yieldEfficiency' => (float) $snapshot->yield_efficiency,
            'actualYield' => (float) $snapshot->actual_yield,
            'expectedYield' => (float) $snapshot->expected_yield,
            'period' => [
                'start' => optional($cycle->start_date)->toDateString(),
                'end' => optional($cycle->completed_at ?: $cycle->expected_harvest_date)->toDateString(),
            ],
            'cycles' => [$this->topCropPayload($cycle, $snapshot)],
            'costBreakdown' => $this->costDistribution(collect([$snapshot])),
            'trendInsights' => [],
            'recommendations' => [],
            'snapshot' => $this->snapshotPayload($snapshot),
        ];
    }

    private function snapshotPayload(ProfitabilitySnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'cropCycleId' => $snapshot->crop_cycle_id,
            'farmId' => $snapshot->farm_id,
            'totalExpenses' => (float) $snapshot->total_expenses,
            'labourCost' => (float) $snapshot->labour_cost,
            'inputCost' => (float) $snapshot->input_cost,
            'inventoryCost' => (float) $snapshot->inventory_cost,
            'transportCost' => (float) $snapshot->transport_cost,
            'processingCost' => (float) $snapshot->processing_cost,
            'storageCost' => (float) $snapshot->storage_cost,
            'miscellaneousCost' => (float) $snapshot->miscellaneous_cost,
            'totalRevenue' => (float) $snapshot->total_revenue,
            'grossSales' => (float) $snapshot->gross_sales,
            'deductionsAmount' => (float) $snapshot->deductions_amount,
            'netSales' => (float) $snapshot->net_sales,
            'costOfProduction' => (float) $snapshot->cost_of_production,
            'grossProfit' => (float) $snapshot->gross_profit,
            'netProfit' => (float) $snapshot->net_profit,
            'profitMargin' => (float) $snapshot->profit_margin,
            'roiPercentage' => (float) $snapshot->roi_percentage,
            'expectedYield' => (float) $snapshot->expected_yield,
            'actualYield' => (float) $snapshot->actual_yield,
            'yieldEfficiency' => (float) $snapshot->yield_efficiency,
            'yieldUnit' => $snapshot->yield_unit,
            'profitPerKg' => (float) $snapshot->profit_per_kg,
            'profitPerBed' => (float) $snapshot->profit_per_bed,
            'profitPerAcre' => (float) $snapshot->profit_per_acre,
            'costPerKg' => (float) $snapshot->cost_per_kg,
            'revenuePerKg' => (float) $snapshot->revenue_per_kg,
            'totalArea' => (float) $snapshot->total_area,
            'areaUnit' => $snapshot->area_unit,
            'numberOfBeds' => (int) $snapshot->number_of_beds,
            'labourCostPercentage' => (float) $snapshot->labour_cost_percentage,
            'inputCostPercentage' => (float) $snapshot->input_cost_percentage,
            'transportCostPercentage' => (float) $snapshot->transport_cost_percentage,
            'processingCostPercentage' => (float) ($snapshot->cost_of_production > 0
                ? ($snapshot->processing_cost / $snapshot->cost_of_production) * 100
                : 0),
            'inventoryCostPercentage' => (float) $snapshot->inventory_cost_percentage,
            'otherCostPercentage' => (float) $snapshot->other_cost_percentage,
            'seasonHealthScore' => (float) $snapshot->season_health_score,
            'seasonHealthGrade' => $this->grade((float) $snapshot->season_health_score),
            'performanceRank' => 0,
            'calculatedAt' => $snapshot->calculated_at->toISOString(),
            'createdAt' => $snapshot->created_at->toISOString(),
            'updatedAt' => $snapshot->updated_at->toISOString(),
        ];
    }

    private function topCropPayload(CropCycle $cycle, ?ProfitabilitySnapshot $snapshot): array
    {
        return [
            'id' => $cycle->id,
            'name' => $cycle->crop_name,
            'rank' => 0,
            'cycles' => 1,
            'totalHarvest' => (float) ($snapshot?->actual_yield ?? 0),
            'unit' => $snapshot?->yield_unit ?? 'kg',
            'totalArea' => (float) ($cycle->land_area ?? 0),
            'areaUnit' => $cycle->land_area_unit ?? 'acres',
            'profit' => (float) ($snapshot?->net_profit ?? 0),
            'roi' => (float) ($snapshot?->roi_percentage ?? 0),
            'totalRevenue' => (float) ($snapshot?->total_revenue ?? 0),
            'totalCosts' => (float) ($snapshot?->cost_of_production ?? 0),
            'yieldEfficiency' => (float) ($snapshot?->yield_efficiency ?? 0),
            'profitPerKg' => (float) ($snapshot?->profit_per_kg ?? 0),
            'grade' => $this->grade((float) ($snapshot?->season_health_score ?? 0)),
            'trend' => 'stable',
        ];
    }

    private function costDistribution($snapshots): array
    {
        $amounts = [
            'labour' => (float) $snapshots->sum('labour_cost'),
            'inputs' => (float) $snapshots->sum('input_cost'),
            'inventory' => (float) $snapshots->sum('inventory_cost'),
            'transport' => (float) $snapshots->sum('transport_cost'),
            'other' => (float) $snapshots->sum('miscellaneous_cost') + (float) $snapshots->sum('storage_cost'),
        ];
        $total = array_sum($amounts);
        $colors = ['labour' => '#2563EB', 'inputs' => '#16A34A', 'inventory' => '#CA8A04', 'transport' => '#DC2626', 'other' => '#6B7280'];

        return collect($amounts)->map(fn ($amount, $name) => [
            'name' => $name,
            'category' => $name,
            'amount' => round($amount, 2),
            'percentage' => $total > 0 ? round(($amount / $total) * 100, 2) : 0,
            'color' => $colors[$name],
            'trend' => 0,
            'benchmark' => 0,
        ])->values()->all();
    }

    private function snapshotInsights(ProfitabilitySnapshot $snapshot, CropCycle $cycle): array
    {
        return collect($snapshot->generateInsights())->map(function ($insight, $index) use ($snapshot, $cycle) {
            $type = $insight['type'] === 'profit_warning' ? 'alert' : ($insight['type'] === 'yield_performance' ? 'warning' : 'info');
            return [
                'id' => $snapshot->id . '-' . $index,
                'type' => $type,
                'title' => $cycle->crop_name . ' profitability',
                'description' => $insight['message'],
                'priority' => $insight['priority'],
                'category' => $insight['type'] === 'yield_performance' ? 'yield' : 'cost',
                'impact' => abs((float) $snapshot->net_profit),
                'createdAt' => $snapshot->calculated_at->toISOString(),
            ];
        })->all();
    }

    private function recentActivity(string $farmId): array
    {
        $sales = Sale::approved()->latest('sale_date')->limit(3)->get()->map(fn ($sale) => [
            'id' => $sale->id, 'type' => 'sale', 'title' => 'Approved sale',
            'description' => $sale->buyer_name ?: 'Sale income', 'amount' => (float) $sale->net_income,
            'date' => $sale->sale_date->toISOString(), 'icon' => 'sale', 'cropId' => $sale->crop_cycle_id,
        ]);
        $expenses = Expense::approved()->where('is_deleted', false)->latest('expense_date')->limit(3)->get()->map(fn ($expense) => [
            'id' => $expense->id, 'type' => 'expense', 'title' => 'Approved expense',
            'description' => $expense->description, 'amount' => -(float) $expense->amount,
            'date' => $expense->expense_date->toISOString(), 'icon' => 'expense', 'cropId' => $expense->crop_cycle_id,
        ]);
        $harvests = Harvest::approved()->latest('harvest_date')->limit(3)->get()->map(fn ($harvest) => [
            'id' => $harvest->id, 'type' => 'harvest', 'title' => 'Approved harvest',
            'description' => $harvest->total_quantity . ' ' . $harvest->unit, 'amount' => 0,
            'date' => $harvest->harvest_date->toISOString(), 'icon' => 'harvest', 'cropId' => $harvest->crop_cycle_id,
        ]);

        return $sales->concat($expenses)->concat($harvests)->sortByDesc('date')->take(8)->values()->all();
    }

    private function benchmarkPayload(float $roi, float $yield, float $costs, float $actualYield): array
    {
        $costPerKg = $actualYield > 0 ? $costs / $actualYield : 0;
        return [
            'farmMetrics' => ['roi' => round($roi, 2), 'yieldEfficiency' => round($yield, 2), 'costPerKg' => round($costPerKg, 2)],
            'benchmarkMetrics' => ['roi' => 20, 'yieldEfficiency' => 85, 'costPerKg' => round($costPerKg, 2)],
            'detailedComparisons' => [],
            'recommendations' => [],
            'percentileDistribution' => [],
        ];
    }

    private function farmId(Request $request): string
    {
        return (string) $request->header('X-Tenant-ID');
    }

    private function grade(float $score): string
    {
        return match (true) {
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 55 => 'C',
            $score > 0 => 'D',
            default => 'N/A',
        };
    }

    private function healthStatus(float $score): string
    {
        return match (true) {
            $score >= 85 => 'Excellent season health',
            $score >= 70 => 'Healthy season',
            $score >= 55 => 'Needs attention',
            $score > 0 => 'At risk',
            default => 'No profitability data',
        };
    }
}
