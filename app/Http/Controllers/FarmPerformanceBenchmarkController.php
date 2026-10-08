<?php

namespace App\Http\Controllers;

use App\Models\FarmPerformanceBenchmark;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;

class FarmPerformanceBenchmarkController extends Controller
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
            'crop_type' => 'nullable|string',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'per_page' => 'nullable|integer|min:1|max:100'
        ]);

        $query = FarmPerformanceBenchmark::forFarm($farmId);

        if (isset($validated['crop_type'])) {
            $query->forCropType($validated['crop_type']);
        }

        if (isset($validated['period_start']) && isset($validated['period_end'])) {
            $query->whereBetween('period_start', [$validated['period_start'], $validated['period_end']]);
        }

        $query->orderBy('period_end', 'desc');

        $perPage = $validated['per_page'] ?? 15;
        $benchmarks = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'data' => $benchmarks,
            'meta' => [
                'filters_applied' => array_filter($validated)
            ]
        ]);
    }

    public function show(string $id): JsonResponse
    {
        try {
            $benchmark = FarmPerformanceBenchmark::with(['farm:id,name'])
                ->findOrFail($id);

            // Get recommendations
            $recommendations = $benchmark->generateRecommendations();

            // Calculate period comparison if previous period exists
            $previousBenchmark = FarmPerformanceBenchmark::forFarm($benchmark->farm_id)
                ->where('id', '!=', $id)
                ->where('period_end', '<', $benchmark->period_start)
                ->orderBy('period_end', 'desc')
                ->first();

            $periodComparison = null;
            if ($previousBenchmark) {
                $periodComparison = $this->calculatePeriodComparison($benchmark, $previousBenchmark);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'benchmark' => $benchmark,
                    'recommendations' => $recommendations,
                    'period_comparison' => $periodComparison,
                    'performance_grade' => $this->calculatePerformanceGrade($benchmark->overall_score)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Benchmark not found'
            ], 404);
        }
    }

    public function generate(Request $request): JsonResponse
    {
        try {
            $farmId = (string) $request->header('X-Tenant-ID');
            
            $validated = $request->validate([
                'period' => 'required|string',
                'crop_type' => 'nullable|string',
                'force_regenerate' => 'nullable|boolean'
            ]);

            // Check if benchmark already exists
            $existing = FarmPerformanceBenchmark::forFarm($farmId)
                ->forPeriod($validated['period'])
                ->when($validated['crop_type'] ?? null, function ($query, $cropType) {
                    return $query->forCropType($cropType);
                })
                ->first();

            if ($existing && !($validated['force_regenerate'] ?? false)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Benchmark for this period already exists. Use force_regenerate=true to regenerate.',
                    'data' => $existing
                ], 409);
            }

            // Generate benchmark
            $benchmark = FarmPerformanceBenchmark::calculateForFarm(
                $farmId,
                $validated['period'],
                $validated['crop_type'] ?? null
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Benchmark generated successfully',
                'data' => $benchmark->load(['farm:id,name'])
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate benchmark: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getPerformanceScorecard(Request $request): JsonResponse
    {
        try {
            $farmId = (string) $request->header('X-Tenant-ID');
            
            $validated = $request->validate([
                'period' => 'nullable|string',
                'crop_type' => 'nullable|string'
            ]);

            // Get latest benchmark or specified period
            $query = FarmPerformanceBenchmark::forFarm($farmId);
            
            if (isset($validated['period'])) {
                $query->forPeriod($validated['period']);
            }
            
            if (isset($validated['crop_type'])) {
                $query->forCropType($validated['crop_type']);
            }
            
            $benchmark = $query->orderBy('period_end', 'desc')->first();

            if (!$benchmark) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No benchmark data available for the specified criteria'
                ], 404);
            }

            // Create performance scorecard
            $scorecard = [
                'overall_score' => $benchmark->overall_score,
                'performance_grade' => $this->calculatePerformanceGrade($benchmark->overall_score),
                'period' => [
                    'label' => $benchmark->benchmark_period,
                    'start_date' => $benchmark->period_start,
                    'end_date' => $benchmark->period_end
                ],
                'key_metrics' => [
                    'profitability' => [
                        'score' => $this->calculateMetricScore($benchmark->profit_margin, 50),
                        'value' => $benchmark->profit_margin,
                        'unit' => '%',
                        'label' => 'Profit Margin',
                        'status' => $this->getMetricStatus($benchmark->profit_margin, [10, 20, 30])
                    ],
                    'efficiency' => [
                        'score' => $this->calculateMetricScore($benchmark->average_yield_efficiency, 100),
                        'value' => $benchmark->average_yield_efficiency,
                        'unit' => '%',
                        'label' => 'Yield Efficiency',
                        'status' => $this->getMetricStatus($benchmark->average_yield_efficiency, [60, 75, 85])
                    ],
                    'cost_management' => [
                        'score' => max(0, 100 - $benchmark->labour_cost_ratio - $benchmark->input_cost_ratio),
                        'value' => $benchmark->labour_cost_ratio + $benchmark->input_cost_ratio,
                        'unit' => '%',
                        'label' => 'Cost Control',
                        'status' => $this->getMetricStatus(100 - ($benchmark->labour_cost_ratio + $benchmark->input_cost_ratio), [60, 75, 85])
                    ],
                    'roi' => [
                        'score' => $this->calculateMetricScore($benchmark->roi_percentage, 100),
                        'value' => $benchmark->roi_percentage,
                        'unit' => '%',
                        'label' => 'Return on Investment',
                        'status' => $this->getMetricStatus($benchmark->roi_percentage, [10, 25, 50])
                    ]
                ],
                'strengths' => $benchmark->strength_areas,
                'improvement_areas' => $benchmark->improvement_areas,
                'recommendations' => array_slice($benchmark->generateRecommendations(), 0, 3), // Top 3 recommendations
                'performance_indicators' => [
                    'above_industry_average' => $benchmark->above_industry_average,
                    'industry_percentile' => $benchmark->industry_percentile,
                    'regional_percentile' => $benchmark->regional_percentile,
                    'period_growth' => $benchmark->period_over_period_growth
                ]
            ];

            return response()->json([
                'status' => 'success',
                'data' => $scorecard
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate performance scorecard: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getPerformanceTrends(Request $request): JsonResponse
    {
        try {
            $farmId = (string) $request->header('X-Tenant-ID');
            
            $validated = $request->validate([
                'crop_type' => 'nullable|string',
                'periods' => 'nullable|integer|min:2|max:12'
            ]);

            $periodsCount = $validated['periods'] ?? 6;

            $query = FarmPerformanceBenchmark::forFarm($farmId);
            
            if (isset($validated['crop_type'])) {
                $query->forCropType($validated['crop_type']);
            }

            $benchmarks = $query->orderBy('period_end', 'desc')
                ->limit($periodsCount)
                ->get()
                ->reverse()
                ->values();

            if ($benchmarks->count() < 2) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient data for trend analysis. At least 2 periods required.'
                ], 400);
            }

            $trends = [
                'overall_score' => $this->calculateTrend($benchmarks, 'overall_score'),
                'profit_margin' => $this->calculateTrend($benchmarks, 'profit_margin'),
                'roi_percentage' => $this->calculateTrend($benchmarks, 'roi_percentage'),
                'yield_efficiency' => $this->calculateTrend($benchmarks, 'average_yield_efficiency'),
                'cost_per_kg' => $this->calculateTrend($benchmarks, 'cost_per_kg'),
                'revenue_per_kg' => $this->calculateTrend($benchmarks, 'revenue_per_kg')
            ];

            // Calculate trend directions
            $trendDirections = [];
            foreach ($trends as $metric => $data) {
                $trendDirections[$metric] = $this->getTrendDirection($data);
            }

            return response()->json([
                'status' => 'success',
                'data' => [
                    'trends' => $trends,
                    'trend_directions' => $trendDirections,
                    'period_range' => [
                        'start' => $benchmarks->first()->benchmark_period,
                        'end' => $benchmarks->last()->benchmark_period,
                        'total_periods' => $benchmarks->count()
                    ],
                    'summary' => $this->generateTrendSummary($trendDirections)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to calculate performance trends: ' . $e->getMessage()
            ], 500);
        }
    }

    public function compareFarms(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'farm_ids' => 'required|array|min:2|max:5',
                'farm_ids.*' => 'uuid|exists:farms,id',
                'period' => 'required|string',
                'crop_type' => 'nullable|string'
            ]);

            $benchmarks = FarmPerformanceBenchmark::whereIn('farm_id', $validated['farm_ids'])
                ->forPeriod($validated['period'])
                ->when($validated['crop_type'] ?? null, function ($query, $cropType) {
                    return $query->forCropType($cropType);
                })
                ->with(['farm:id,name'])
                ->get();

            if ($benchmarks->count() < 2) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient benchmark data for comparison. At least 2 farms with data required.'
                ], 400);
            }

            $comparison = [];
            $metrics = [
                'overall_score', 'profit_margin', 'roi_percentage', 'average_yield_efficiency',
                'cost_per_kg', 'revenue_per_kg', 'labour_efficiency', 'input_efficiency'
            ];

            foreach ($benchmarks as $benchmark) {
                $farmData = [
                    'farm_id' => $benchmark->farm_id,
                    'farm_name' => $benchmark->farm->name,
                    'metrics' => []
                ];

                foreach ($metrics as $metric) {
                    $farmData['metrics'][$metric] = [
                        'value' => $benchmark->$metric,
                        'rank' => null // Will be calculated below
                    ];
                }

                $comparison[] = $farmData;
            }

            // Calculate rankings for each metric
            foreach ($metrics as $metric) {
                $sorted = collect($comparison)->sortByDesc("metrics.{$metric}.value")->values();
                foreach ($sorted as $index => $farm) {
                    $farmIndex = collect($comparison)->search(function ($item) use ($farm) {
                        return $item['farm_id'] === $farm['farm_id'];
                    });
                    $comparison[$farmIndex]['metrics'][$metric]['rank'] = $index + 1;
                }
            }

            // Generate insights
            $insights = $this->generateComparisonInsights($comparison, $metrics);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'comparison' => $comparison,
                    'insights' => $insights,
                    'period' => $validated['period'],
                    'crop_type' => $validated['crop_type'] ?? 'all',
                    'farms_compared' => $benchmarks->count()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to compare farms: ' . $e->getMessage()
            ], 500);
        }
    }

    // Helper methods
    private function calculatePerformanceGrade(float $score): string
    {
        return match(true) {
            $score >= 90 => 'A+',
            $score >= 85 => 'A',
            $score >= 80 => 'A-',
            $score >= 75 => 'B+',
            $score >= 70 => 'B',
            $score >= 65 => 'B-',
            $score >= 60 => 'C+',
            $score >= 55 => 'C',
            $score >= 50 => 'C-',
            default => 'D'
        };
    }

    private function calculatePeriodComparison($current, $previous): array
    {
        $metrics = ['overall_score', 'profit_margin', 'roi_percentage', 'average_yield_efficiency'];
        $comparison = [];

        foreach ($metrics as $metric) {
            $currentValue = $current->$metric;
            $previousValue = $previous->$metric;
            $change = $currentValue - $previousValue;
            $percentageChange = $previousValue > 0 ? ($change / $previousValue) * 100 : 0;

            $comparison[$metric] = [
                'current' => $currentValue,
                'previous' => $previousValue,
                'change' => $change,
                'percentage_change' => round($percentageChange, 2),
                'trend' => $change > 0 ? 'improving' : ($change < 0 ? 'declining' : 'stable')
            ];
        }

        return $comparison;
    }

    private function calculateMetricScore(float $value, float $maxValue): float
    {
        return min(($value / $maxValue) * 100, 100);
    }

    private function getMetricStatus(float $value, array $thresholds): string
    {
        [$poor, $fair, $good] = $thresholds;
        
        return match(true) {
            $value >= $good => 'excellent',
            $value >= $fair => 'good',
            $value >= $poor => 'fair',
            default => 'poor'
        };
    }

    private function calculateTrend($benchmarks, string $metric): array
    {
        return $benchmarks->map(function ($benchmark) use ($metric) {
            return [
                'period' => $benchmark->benchmark_period,
                'value' => $benchmark->$metric
            ];
        })->toArray();
    }

    private function getTrendDirection(array $trendData): string
    {
        if (count($trendData) < 2) return 'insufficient_data';
        
        $first = $trendData[0]['value'];
        $last = end($trendData)['value'];
        
        $change = (($last - $first) / $first) * 100;
        
        return match(true) {
            $change > 10 => 'strongly_improving',
            $change > 5 => 'improving',
            $change > -5 => 'stable',
            $change > -10 => 'declining',
            default => 'strongly_declining'
        };
    }

    private function generateTrendSummary(array $trendDirections): array
    {
        $improving = 0;
        $declining = 0;
        $stable = 0;

        foreach ($trendDirections as $direction) {
            match($direction) {
                'strongly_improving', 'improving' => $improving++,
                'strongly_declining', 'declining' => $declining++,
                default => $stable++
            };
        }

        return [
            'improving_metrics' => $improving,
            'declining_metrics' => $declining,
            'stable_metrics' => $stable,
            'overall_trend' => $improving > $declining ? 'improving' : ($declining > $improving ? 'declining' : 'stable')
        ];
    }

    private function generateComparisonInsights(array $comparison, array $metrics): array
    {
        $insights = [];
        
        // Find best and worst performers overall
        $overallScores = collect($comparison)->pluck('metrics.overall_score.value', 'farm_name');
        $bestPerformer = $overallScores->keys()[$overallScores->search($overallScores->max())];
        $worstPerformer = $overallScores->keys()[$overallScores->search($overallScores->min())];
        
        $insights[] = [
            'type' => 'best_performer',
            'message' => "{$bestPerformer} has the highest overall performance score",
            'data' => ['farm' => $bestPerformer, 'score' => $overallScores->max()]
        ];
        
        $insights[] = [
            'type' => 'improvement_opportunity',
            'message' => "{$worstPerformer} has the most room for improvement",
            'data' => ['farm' => $worstPerformer, 'score' => $overallScores->min()]
        ];

        // Find metric-specific leaders
        foreach (['profit_margin', 'average_yield_efficiency'] as $metric) {
            $metricValues = collect($comparison)->pluck("metrics.{$metric}.value", 'farm_name');
            $leader = $metricValues->keys()[$metricValues->search($metricValues->max())];
            
            $insights[] = [
                'type' => 'metric_leader',
                'message' => "{$leader} leads in " . str_replace('_', ' ', $metric),
                'data' => ['farm' => $leader, 'metric' => $metric, 'value' => $metricValues->max()]
            ];
        }

        return $insights;
    }
}
