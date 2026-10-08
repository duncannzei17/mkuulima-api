<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Traits\HasUuids;

class BedAnalytics extends Model
{
    use HasUuids;

    protected $fillable = [
        'bed_id', 'farm_id', 'analysis_date', 'analysis_period',
        'overall_performance_score', 'yield_performance_score', 'cost_efficiency_score',
        'timeline_performance_score', 'quality_performance_score', 'total_yield_kg',
        'average_yield_per_cycle', 'yield_per_sqm', 'yield_trend_percentage',
        'successful_harvests', 'failed_harvests', 'total_costs', 'labour_costs',
        'input_costs', 'harvest_costs', 'maintenance_costs', 'cost_per_kg',
        'cost_per_sqm', 'cost_trend_percentage', 'total_revenue', 'gross_profit',
        'net_profit', 'profit_margin_percentage', 'roi_percentage', 'revenue_per_sqm',
        'utilization_rate_percentage', 'idle_days', 'productive_days',
        'crop_rotation_efficiency', 'average_health_score', 'health_trend_percentage',
        'total_observations', 'critical_issues', 'maintenance_interventions',
        'downtime_days', 'average_harvest_quality_score', 'marketable_yield_percentage',
        'waste_percentage', 'quality_consistency_score', 'water_usage_liters',
        'water_efficiency_score', 'soil_health_impact', 'sustainable_practices_count',
        'farm_average_comparison', 'farm_ranking', 'peer_comparison_score',
        'performance_category', 'yield_trend_data', 'cost_trend_data',
        'health_trend_data', 'seasonal_patterns', 'predicted_next_yield',
        'predicted_next_cost', 'predicted_profitability', 'confidence_level',
        'risk_level', 'risk_factors', 'failure_probability', 'recommendations',
        'season', 'weather_impact_analysis', 'weather_adaptation_score',
        'calculated_by', 'calculation_parameters', 'is_validated', 'validated_by'
    ];

    protected $casts = [
        'analysis_date' => 'date',
        'overall_performance_score' => 'decimal:2',
        'yield_performance_score' => 'decimal:2',
        'cost_efficiency_score' => 'decimal:2',
        'timeline_performance_score' => 'decimal:2',
        'quality_performance_score' => 'decimal:2',
        'total_yield_kg' => 'decimal:2',
        'average_yield_per_cycle' => 'decimal:2',
        'yield_per_sqm' => 'decimal:2',
        'yield_trend_percentage' => 'decimal:2',
        'total_costs' => 'decimal:2',
        'labour_costs' => 'decimal:2',
        'input_costs' => 'decimal:2',
        'harvest_costs' => 'decimal:2',
        'maintenance_costs' => 'decimal:2',
        'cost_per_kg' => 'decimal:2',
        'cost_per_sqm' => 'decimal:2',
        'cost_trend_percentage' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'gross_profit' => 'decimal:2',
        'net_profit' => 'decimal:2',
        'profit_margin_percentage' => 'decimal:2',
        'roi_percentage' => 'decimal:2',
        'revenue_per_sqm' => 'decimal:2',
        'utilization_rate_percentage' => 'decimal:2',
        'crop_rotation_efficiency' => 'decimal:2',
        'average_health_score' => 'decimal:2',
        'health_trend_percentage' => 'decimal:2',
        'downtime_days' => 'decimal:2',
        'average_harvest_quality_score' => 'decimal:2',
        'marketable_yield_percentage' => 'decimal:2',
        'waste_percentage' => 'decimal:2',
        'quality_consistency_score' => 'decimal:2',
        'water_usage_liters' => 'decimal:2',
        'water_efficiency_score' => 'decimal:2',
        'soil_health_impact' => 'decimal:2',
        'farm_average_comparison' => 'decimal:2',
        'peer_comparison_score' => 'decimal:2',
        'yield_trend_data' => 'array',
        'cost_trend_data' => 'array',
        'health_trend_data' => 'array',
        'seasonal_patterns' => 'array',
        'predicted_next_yield' => 'decimal:2',
        'predicted_next_cost' => 'decimal:2',
        'predicted_profitability' => 'decimal:2',
        'risk_factors' => 'array',
        'failure_probability' => 'decimal:2',
        'weather_impact_analysis' => 'array',
        'weather_adaptation_score' => 'decimal:2',
        'calculation_parameters' => 'array',
        'is_validated' => 'boolean',
        'calculated_at' => 'datetime',
        'validated_at' => 'datetime'
    ];

    // Analysis periods
    public const ANALYSIS_PERIODS = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'seasonal' => 'Seasonal',
        'annual' => 'Annual'
    ];

    // Performance categories
    public const PERFORMANCE_CATEGORIES = [
        'top_performer' => 'Top Performer',
        'above_average' => 'Above Average',
        'average' => 'Average',
        'below_average' => 'Below Average',
        'poor_performer' => 'Poor Performer'
    ];

    // Risk levels
    public const RISK_LEVELS = [
        'very_low' => 'Very Low',
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'very_high' => 'Very High'
    ];

    // Relationships
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function calculatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    // Scopes
    public function scopeForBed($query, $bedId)
    {
        return $query->where('bed_id', $bedId);
    }

    public function scopeByPeriod($query, $period)
    {
        return $query->where('analysis_period', $period);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('performance_category', $category);
    }

    public function scopeByRiskLevel($query, $riskLevel)
    {
        return $query->where('risk_level', $riskLevel);
    }

    public function scopeTopPerformers($query)
    {
        return $query->where('performance_category', 'top_performer');
    }

    public function scopePoorPerformers($query)
    {
        return $query->where('performance_category', 'poor_performer');
    }

    public function scopeRecent($query, $months = 3)
    {
        return $query->where('analysis_date', '>=', now()->subMonths($months));
    }

    public function scopeValidated($query)
    {
        return $query->where('is_validated', true);
    }

    // Static Analysis Methods

    /**
     * Generate comprehensive analytics for a bed
     */
    public static function generateAnalyticsForBed(Bed $bed, string $period = 'monthly', ?Carbon $analysisDate = null): self
    {
        $analysisDate = $analysisDate ?? now();
        $analytics = new static([
            'bed_id' => $bed->id,
            'farm_id' => $bed->farm_id,
            'analysis_date' => $analysisDate->toDateString(),
            'analysis_period' => $period,
            'calculated_at' => now(),
            'calculated_by' => auth()->id()
        ]);

        $analytics->calculateAllMetrics($bed, $period, $analysisDate);
        $analytics->save();

        return $analytics;
    }

    /**
     * Calculate all analytics metrics
     */
    public function calculateAllMetrics(Bed $bed, string $period, Carbon $analysisDate): void
    {
        $dateRange = $this->getDateRangeForPeriod($period, $analysisDate);
        
        // Performance Scores
        $this->calculatePerformanceScores($bed, $dateRange);
        
        // Yield Analytics
        $this->calculateYieldAnalytics($bed, $dateRange);
        
        // Cost Analytics
        $this->calculateCostAnalytics($bed, $dateRange);
        
        // Revenue & Profitability
        $this->calculateProfitabilityAnalytics($bed, $dateRange);
        
        // Resource Utilization
        $this->calculateUtilizationMetrics($bed, $dateRange);
        
        // Health & Maintenance
        $this->calculateHealthMetrics($bed, $dateRange);
        
        // Quality Metrics
        $this->calculateQualityMetrics($bed, $dateRange);
        
        // Environmental Impact
        $this->calculateEnvironmentalMetrics($bed, $dateRange);
        
        // Comparative Analysis
        $this->calculateComparativeMetrics($bed, $dateRange);
        
        // Trend Analysis
        $this->calculateTrendAnalysis($bed, $period, $analysisDate);
        
        // Forecasting
        $this->calculateForecasting($bed, $dateRange);
        
        // Risk Assessment
        $this->calculateRiskAssessment($bed, $dateRange);
        
        // Overall Score
        $this->calculateOverallPerformanceScore();
    }

    /**
     * Calculate performance scores
     */
    private function calculatePerformanceScores(Bed $bed, array $dateRange): void
    {
        $assignments = $this->getAssignmentsInRange($bed, $dateRange);
        
        if ($assignments->isEmpty()) {
            $this->yield_performance_score = 0;
            $this->cost_efficiency_score = 0;
            $this->timeline_performance_score = 0;
            $this->quality_performance_score = 0;
            return;
        }

        // Yield Performance
        $this->yield_performance_score = $assignments->avg('yield_efficiency_score') ?? 0;

        // Cost Efficiency
        $avgCostPerKg = $assignments->where('cost_per_kg', '>', 0)->avg('cost_per_kg');
        $farmAvgCost = $this->getFarmAverageCostPerKg($bed);
        
        if ($farmAvgCost > 0 && $avgCostPerKg > 0) {
            $this->cost_efficiency_score = max(0, (1 - ($avgCostPerKg / $farmAvgCost)) * 100 + 100);
        }

        // Timeline Performance
        $timelineVariances = $assignments->pluck('timeline_variance_days')->filter()->values();
        if ($timelineVariances->isNotEmpty()) {
            $avgVariance = abs($timelineVariances->avg());
            $this->timeline_performance_score = max(0, 100 - ($avgVariance * 5));
        }

        // Quality Performance  
        $qualityScores = $assignments->whereNotNull('harvest_quality')
            ->map(function($assignment) {
                return match($assignment->harvest_quality) {
                    'excellent' => 100,
                    'good' => 80,
                    'fair' => 60,
                    'poor' => 30,
                    default => 50
                };
            });
        
        $this->quality_performance_score = $qualityScores->avg() ?? 0;
    }

    /**
     * Calculate yield analytics
     */
    private function calculateYieldAnalytics(Bed $bed, array $dateRange): void
    {
        $assignments = $this->getAssignmentsInRange($bed, $dateRange);
        
        $this->total_yield_kg = $assignments->sum('actual_yield_kg');
        $this->average_yield_per_cycle = $assignments->avg('actual_yield_kg') ?? 0;
        $this->successful_harvests = $assignments->where('assignment_status', 'completed')->count();
        $this->failed_harvests = $assignments->whereIn('assignment_status', ['failed', 'abandoned'])->count();
        
        if ($bed->size_area && $bed->size_area > 0) {
            $this->yield_per_sqm = $this->total_yield_kg / $bed->size_area;
        }

        // Calculate yield trend
        $this->calculateYieldTrend($bed, $dateRange);
    }

    /**
     * Calculate cost analytics
     */
    private function calculateCostAnalytics(Bed $bed, array $dateRange): void
    {
        $assignments = $this->getAssignmentsInRange($bed, $dateRange);
        
        $this->labour_costs = $assignments->sum('total_labour_cost');
        $this->input_costs = $assignments->sum('total_input_cost');
        $this->harvest_costs = $assignments->sum('total_harvest_cost');
        $this->total_costs = $this->labour_costs + $this->input_costs + $this->harvest_costs;
        
        if ($this->total_yield_kg > 0) {
            $this->cost_per_kg = $this->total_costs / $this->total_yield_kg;
        }
        
        if ($bed->size_area && $bed->size_area > 0) {
            $this->cost_per_sqm = $this->total_costs / $bed->size_area;
        }

        // Calculate cost trend
        $this->calculateCostTrend($bed, $dateRange);
    }

    /**
     * Calculate profitability analytics
     */
    private function calculateProfitabilityAnalytics(Bed $bed, array $dateRange): void
    {
        $assignments = $this->getAssignmentsInRange($bed, $dateRange);
        
        $this->total_revenue = $assignments->sum('total_revenue');
        $this->gross_profit = $this->total_revenue - $this->input_costs;
        $this->net_profit = $this->total_revenue - $this->total_costs;
        
        if ($this->total_revenue > 0) {
            $this->profit_margin_percentage = ($this->net_profit / $this->total_revenue) * 100;
        }
        
        if ($this->total_costs > 0) {
            $this->roi_percentage = ($this->net_profit / $this->total_costs) * 100;
        }
        
        if ($bed->size_area && $bed->size_area > 0) {
            $this->revenue_per_sqm = $this->total_revenue / $bed->size_area;
        }
    }

    /**
     * Calculate utilization metrics
     */
    private function calculateUtilizationMetrics(Bed $bed, array $dateRange): void
    {
        $totalDays = Carbon::parse($dateRange['start'])->diffInDays(Carbon::parse($dateRange['end']));
        
        $assignments = $this->getAssignmentsInRange($bed, $dateRange);
        $this->productive_days = $assignments->sum(function($assignment) {
            $start = max(Carbon::parse($assignment->start_date), Carbon::parse($this->analysis_date)->subMonth());
            $end = min(Carbon::parse($assignment->actual_end_date ?? now()), Carbon::parse($this->analysis_date));
            return $start->diffInDays($end);
        });
        
        $this->idle_days = max(0, $totalDays - $this->productive_days);
        $this->utilization_rate_percentage = $totalDays > 0 ? ($this->productive_days / $totalDays) * 100 : 0;
        
        // Calculate crop rotation efficiency
        $this->calculateCropRotationEfficiency($bed, $dateRange);
    }

    /**
     * Calculate health metrics
     */
    private function calculateHealthMetrics(Bed $bed, array $dateRange): void
    {
        $this->average_health_score = $bed->health_score;
        
        $observations = $bed->notes()
            ->whereBetween('note_date', [$dateRange['start'], $dateRange['end']])
            ->get();
        
        $this->total_observations = $observations->count();
        $this->critical_issues = $observations->where('severity', 'critical')->count();
        
        // Calculate health trend
        $this->calculateHealthTrend($bed, $dateRange);
    }

    /**
     * Calculate quality metrics
     */
    private function calculateQualityMetrics(Bed $bed, array $dateRange): void
    {
        $assignments = $this->getAssignmentsInRange($bed, $dateRange);
        
        $qualityScores = $assignments->whereNotNull('harvest_quality')
            ->map(function($assignment) {
                return match($assignment->harvest_quality) {
                    'excellent' => 100,
                    'good' => 80,
                    'fair' => 60,
                    'poor' => 30,
                    default => 50
                };
            });
        
        $this->average_harvest_quality_score = $qualityScores->avg() ?? 0;
        $this->marketable_yield_percentage = $assignments->avg('marketable_yield_percentage') ?? 100;
        $this->waste_percentage = $assignments->avg('waste_percentage') ?? 0;
        
        // Calculate quality consistency
        if ($qualityScores->count() > 1) {
            $variance = $this->calculateVariance($qualityScores->toArray());
            $this->quality_consistency_score = max(0, 100 - ($variance * 2));
        } else {
            $this->quality_consistency_score = $qualityScores->isNotEmpty() ? 100 : 0;
        }
    }

    /**
     * Calculate environmental metrics
     */
    private function calculateEnvironmentalMetrics(Bed $bed, array $dateRange): void
    {
        $assignments = $this->getAssignmentsInRange($bed, $dateRange);
        
        $this->water_usage_liters = $assignments->sum('water_usage_liters');
        
        if ($this->water_usage_liters > 0 && $this->total_yield_kg > 0) {
            $this->water_efficiency_score = min(100, (100 / ($this->water_usage_liters / $this->total_yield_kg)) * 10);
        }
        
        // Soil health impact
        $assignments_with_health = $assignments->whereNotNull('health_impact');
        $this->soil_health_impact = $assignments_with_health->avg('health_impact') ?? 0;
        
        // Sustainable practices count
        $this->sustainable_practices_count = $assignments->sum(function($assignment) {
            $practices = 0;
            if ($assignment->irrigation_used) $practices++;
            // Add more sustainable practice checks as needed
            return $practices;
        });
    }

    /**
     * Calculate comparative metrics
     */
    private function calculateComparativeMetrics(Bed $bed, array $dateRange): void
    {
        // Farm average comparison
        $farmBeds = Bed::where('farm_id', $bed->farm_id)
            ->where('id', '!=', $bed->id)
            ->active()
            ->get();
        
        if ($farmBeds->isNotEmpty()) {
            $farmAvgYield = $farmBeds->avg('average_yield_per_cycle') ?? 1;
            if ($farmAvgYield > 0) {
                $this->farm_average_comparison = (($this->average_yield_per_cycle / $farmAvgYield) - 1) * 100;
            }
            
            // Calculate farm ranking
            $this->calculateFarmRanking($bed, $farmBeds);
        }

        // Determine performance category
        $this->performance_category = $this->determinePerformanceCategory();
    }

    /**
     * Calculate overall performance score
     */
    private function calculateOverallPerformanceScore(): void
    {
        $scores = collect([
            $this->yield_performance_score * 0.3,
            $this->cost_efficiency_score * 0.25,
            $this->timeline_performance_score * 0.2,
            $this->quality_performance_score * 0.15,
            $this->average_health_score * 0.1
        ])->filter();

        $this->overall_performance_score = $scores->sum();
    }

    // Helper Methods

    private function getDateRangeForPeriod(string $period, Carbon $analysisDate): array
    {
        return match($period) {
            'daily' => [
                'start' => $analysisDate->copy()->startOfDay()->toDateString(),
                'end' => $analysisDate->copy()->endOfDay()->toDateString()
            ],
            'weekly' => [
                'start' => $analysisDate->copy()->startOfWeek()->toDateString(),
                'end' => $analysisDate->copy()->endOfWeek()->toDateString()
            ],
            'monthly' => [
                'start' => $analysisDate->copy()->startOfMonth()->toDateString(),
                'end' => $analysisDate->copy()->endOfMonth()->toDateString()
            ],
            'seasonal' => [
                'start' => $analysisDate->copy()->subMonths(3)->toDateString(),
                'end' => $analysisDate->copy()->toDateString()
            ],
            'annual' => [
                'start' => $analysisDate->copy()->startOfYear()->toDateString(),
                'end' => $analysisDate->copy()->endOfYear()->toDateString()
            ],
            default => [
                'start' => $analysisDate->copy()->startOfMonth()->toDateString(),
                'end' => $analysisDate->copy()->endOfMonth()->toDateString()
            ]
        };
    }

    private function getAssignmentsInRange(Bed $bed, array $dateRange)
    {
        return $bed->cropAssignments()
            ->where(function($query) use ($dateRange) {
                $query->whereBetween('start_date', [$dateRange['start'], $dateRange['end']])
                      ->orWhereBetween('actual_end_date', [$dateRange['start'], $dateRange['end']])
                      ->orWhere(function($q) use ($dateRange) {
                          $q->where('start_date', '<=', $dateRange['start'])
                            ->where(function($q2) use ($dateRange) {
                                $q2->where('actual_end_date', '>=', $dateRange['end'])
                                   ->orWhereNull('actual_end_date');
                            });
                      });
            })
            ->get();
    }

    private function getFarmAverageCostPerKg(Bed $bed): float
    {
        return BedCropAssignment::where('farm_id', $bed->farm_id)
            ->where('cost_per_kg', '>', 0)
            ->avg('cost_per_kg') ?? 0;
    }

    private function calculateVariance(array $values): float
    {
        if (count($values) < 2) return 0;
        
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(function($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $values)) / count($values);
        
        return sqrt($variance);
    }

    private function calculateYieldTrend(Bed $bed, array $dateRange): void
    {
        // Implementation for yield trend calculation
        $this->yield_trend_percentage = 0; // Placeholder
    }

    private function calculateCostTrend(Bed $bed, array $dateRange): void
    {
        // Implementation for cost trend calculation  
        $this->cost_trend_percentage = 0; // Placeholder
    }

    private function calculateHealthTrend(Bed $bed, array $dateRange): void
    {
        // Implementation for health trend calculation
        $this->health_trend_percentage = 0; // Placeholder
    }

    private function calculateCropRotationEfficiency(Bed $bed, array $dateRange): void
    {
        // Implementation for crop rotation efficiency
        $this->crop_rotation_efficiency = 80; // Placeholder
    }

    private function calculateTrendAnalysis(Bed $bed, string $period, Carbon $analysisDate): void
    {
        // Implementation for trend analysis
        $this->yield_trend_data = [];
        $this->cost_trend_data = [];
        $this->health_trend_data = [];
    }

    private function calculateForecasting(Bed $bed, array $dateRange): void
    {
        // Simple forecasting based on recent trends
        $this->predicted_next_yield = $this->average_yield_per_cycle;
        $this->predicted_next_cost = $this->cost_per_kg * $this->predicted_next_yield;
        $this->predicted_profitability = ($this->predicted_next_yield * $this->cost_per_kg * 1.2) - $this->predicted_next_cost;
        $this->confidence_level = 70;
    }

    private function calculateRiskAssessment(Bed $bed, array $dateRange): void
    {
        $riskScore = 0;
        $riskFactors = [];

        // Health risk
        if ($this->average_health_score < 60) {
            $riskScore += 20;
            $riskFactors[] = 'Poor bed health';
        }

        // Performance risk
        if ($this->yield_performance_score < 50) {
            $riskScore += 15;
            $riskFactors[] = 'Low yield performance';
        }

        // Cost risk
        if ($this->cost_efficiency_score < 50) {
            $riskScore += 15;
            $riskFactors[] = 'High cost inefficiency';
        }

        // Critical issues risk
        if ($this->critical_issues > 2) {
            $riskScore += 10;
            $riskFactors[] = 'Multiple critical issues';
        }

        $this->risk_level = match(true) {
            $riskScore >= 40 => 'very_high',
            $riskScore >= 30 => 'high',
            $riskScore >= 20 => 'medium',
            $riskScore >= 10 => 'low',
            default => 'very_low'
        };

        $this->failure_probability = min(100, $riskScore * 2);
        $this->risk_factors = $riskFactors;

        // Generate recommendations
        $this->recommendations = $this->generateRecommendations($riskFactors);
    }

    private function calculateFarmRanking(Bed $bed, $farmBeds): void
    {
        $bedPerformances = $farmBeds->map(function($b) {
            return [
                'id' => $b->id,
                'score' => $b->yield_performance_score + $b->cost_efficiency_score + $b->health_score
            ];
        })->sortByDesc('score')->values();

        $bedPerformances->push([
            'id' => $bed->id,
            'score' => $this->yield_performance_score + $this->cost_efficiency_score + $this->average_health_score
        ]);

        $ranked = $bedPerformances->sortByDesc('score')->values();
        $this->farm_ranking = $ranked->search(function($item) use ($bed) {
            return $item['id'] === $bed->id;
        }) + 1;
    }

    private function determinePerformanceCategory(): string
    {
        $score = $this->overall_performance_score;

        return match(true) {
            $score >= 85 => 'top_performer',
            $score >= 70 => 'above_average',
            $score >= 50 => 'average',
            $score >= 30 => 'below_average',
            default => 'poor_performer'
        };
    }

    private function generateRecommendations(array $riskFactors): string
    {
        if (empty($riskFactors)) {
            return 'Maintain current excellent performance. Continue monitoring for optimization opportunities.';
        }

        $recommendations = [];

        foreach ($riskFactors as $factor) {
            $recommendations[] = match($factor) {
                'Poor bed health' => 'Implement soil health improvement program and address critical observations',
                'Low yield performance' => 'Review crop varieties and growing practices for yield optimization',
                'High cost inefficiency' => 'Analyze input costs and labor efficiency for cost reduction opportunities',
                'Multiple critical issues' => 'Prioritize resolution of critical issues and implement preventive measures',
                default => 'Monitor and address identified risk factor'
            };
        }

        return implode('. ', $recommendations) . '.';
    }
}