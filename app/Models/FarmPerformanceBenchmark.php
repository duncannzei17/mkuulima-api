<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasUuids;
use App\Events\FarmPerformanceBenchmarkGenerated;
use Carbon\Carbon;

class FarmPerformanceBenchmark extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'farm_id',
        'benchmark_period',
        'period_start',
        'period_end',
        'crop_type',
        'total_revenue',
        'total_costs',
        'net_profit',
        'profit_margin',
        'roi_percentage',
        'average_yield_efficiency',
        'cost_per_kg',
        'revenue_per_kg',
        'labour_efficiency',
        'land_utilization',
        'input_efficiency',
        'crop_cycles_completed',
        'average_cycle_duration',
        'labour_cost_ratio',
        'input_cost_ratio',
        'transport_cost_ratio',
        'overhead_cost_ratio',
        'price_realization',
        'sales_efficiency',
        'buyer_relationships_count',
        'overall_score',
        'sustainability_score',
        'innovation_score',
        'industry_percentile',
        'regional_percentile',
        'above_industry_average',
        'period_over_period_growth',
        'improvement_areas',
        'strength_areas'
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_revenue' => 'decimal:2',
        'total_costs' => 'decimal:2',
        'net_profit' => 'decimal:2',
        'profit_margin' => 'decimal:2',
        'roi_percentage' => 'decimal:2',
        'average_yield_efficiency' => 'decimal:2',
        'cost_per_kg' => 'decimal:2',
        'revenue_per_kg' => 'decimal:2',
        'labour_efficiency' => 'decimal:2',
        'land_utilization' => 'decimal:2',
        'input_efficiency' => 'decimal:2',
        'average_cycle_duration' => 'decimal:2',
        'labour_cost_ratio' => 'decimal:2',
        'input_cost_ratio' => 'decimal:2',
        'transport_cost_ratio' => 'decimal:2',
        'overhead_cost_ratio' => 'decimal:2',
        'price_realization' => 'decimal:2',
        'sales_efficiency' => 'decimal:2',
        'overall_score' => 'decimal:2',
        'sustainability_score' => 'decimal:2',
        'innovation_score' => 'decimal:2',
        'industry_percentile' => 'decimal:2',
        'regional_percentile' => 'decimal:2',
        'above_industry_average' => 'boolean',
        'period_over_period_growth' => 'decimal:2',
        'improvement_areas' => 'array',
        'strength_areas' => 'array'
    ];

    // Relationships
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    // Calculation methods
    public function calculateOverallScore(): float
    {
        $weights = [
            'profitability' => 0.30, // 30%
            'efficiency' => 0.25,    // 25%
            'yield' => 0.20,         // 20%
            'cost_management' => 0.15, // 15%
            'market_performance' => 0.10 // 10%
        ];

        $scores = [
            'profitability' => $this->calculateProfitabilityScore(),
            'efficiency' => $this->calculateEfficiencyScore(),
            'yield' => $this->average_yield_efficiency,
            'cost_management' => $this->calculateCostManagementScore(),
            'market_performance' => $this->calculateMarketPerformanceScore()
        ];

        $weightedSum = 0;
        foreach ($weights as $category => $weight) {
            $weightedSum += $scores[$category] * $weight;
        }

        return round($weightedSum, 2);
    }

    private function calculateProfitabilityScore(): float
    {
        // Score based on profit margin and ROI
        $profitScore = min($this->profit_margin * 2, 100); // Max score at 50% margin
        $roiScore = min($this->roi_percentage, 100);       // Max score at 100% ROI
        
        return ($profitScore + $roiScore) / 2;
    }

    private function calculateEfficiencyScore(): float
    {
        // Combine labour efficiency, input efficiency, and land utilization
        return ($this->labour_efficiency + $this->input_efficiency + $this->land_utilization) / 3;
    }

    private function calculateCostManagementScore(): float
    {
        // Lower ratios = better cost management
        $labourScore = max(0, 100 - ($this->labour_cost_ratio * 2));
        $inputScore = max(0, 100 - ($this->input_cost_ratio * 2));
        $transportScore = max(0, 100 - ($this->transport_cost_ratio * 5));
        
        return ($labourScore + $inputScore + $transportScore) / 3;
    }

    private function calculateMarketPerformanceScore(): float
    {
        // Based on price realization and sales efficiency
        return ($this->price_realization + $this->sales_efficiency) / 2;
    }

    public function identifyStrengthsAndWeaknesses(): array
    {
        $metrics = [
            'profitability' => $this->profit_margin,
            'roi' => $this->roi_percentage,
            'yield_efficiency' => $this->average_yield_efficiency,
            'labour_efficiency' => $this->labour_efficiency,
            'input_efficiency' => $this->input_efficiency,
            'land_utilization' => $this->land_utilization,
            'price_realization' => $this->price_realization,
            'cost_management' => 100 - $this->labour_cost_ratio - $this->input_cost_ratio
        ];

        // Define thresholds
        $thresholds = [
            'excellent' => 85,
            'good' => 70,
            'average' => 50,
            'poor' => 30
        ];

        $strengths = [];
        $improvements = [];

        foreach ($metrics as $metric => $value) {
            if ($value >= $thresholds['excellent']) {
                $strengths[] = $metric;
            } elseif ($value < $thresholds['average']) {
                $improvements[] = $metric;
            }
        }

        return [
            'strengths' => $strengths,
            'improvement_areas' => $improvements
        ];
    }

    public function generateRecommendations(): array
    {
        $recommendations = [];
        $analysis = $this->identifyStrengthsAndWeaknesses();

        // Profitability recommendations
        if (in_array('profitability', $analysis['improvement_areas'])) {
            $recommendations[] = [
                'area' => 'profitability',
                'priority' => 'high',
                'action' => 'Focus on cost reduction and premium market access',
                'expected_impact' => 'Increase profit margin by 10-15%'
            ];
        }

        // Yield efficiency recommendations
        if (in_array('yield_efficiency', $analysis['improvement_areas'])) {
            $recommendations[] = [
                'area' => 'yield_efficiency',
                'priority' => 'high',
                'action' => 'Improve farming practices and input optimization',
                'expected_impact' => 'Increase yield by 15-20%'
            ];
        }

        // Cost management recommendations
        if ($this->labour_cost_ratio > 40) {
            $recommendations[] = [
                'area' => 'labour_costs',
                'priority' => 'medium',
                'action' => 'Consider automation or efficiency improvements',
                'expected_impact' => 'Reduce labour costs by 15-25%'
            ];
        }

        // Market performance recommendations
        if ($this->price_realization < 70) {
            $recommendations[] = [
                'area' => 'market_performance',
                'priority' => 'medium',
                'action' => 'Explore premium markets and improve product quality',
                'expected_impact' => 'Increase revenue per kg by 20-30%'
            ];
        }

        return $recommendations;
    }

    // Scopes
    public function scopeForFarm($query, $farmId)
    {
        return $query->where('farm_id', $farmId);
    }

    public function scopeForPeriod($query, $period)
    {
        return $query->where('benchmark_period', $period);
    }

    public function scopeForCropType($query, $cropType)
    {
        return $query->where('crop_type', $cropType);
    }

    public function scopeHighPerforming($query, $threshold = 75)
    {
        return $query->where('overall_score', '>=', $threshold);
    }

    public function scopeAboveAverage($query)
    {
        return $query->where('above_industry_average', true);
    }

    public function scopeRecent($query, $months = 6)
    {
        return $query->where('period_end', '>=', now()->subMonths($months));
    }

    // Static methods
    public static function calculateForFarm(string $farmId, string $period, ?string $cropType = null): self
    {
        $farm = Farm::findOrFail($farmId);
        
        // Determine period dates
        $dates = static::parsePeriodDates($period);
        
        // Get profitability snapshots for the period
        $snapshots = ProfitabilitySnapshot::forFarm($farmId)
            ->whereBetween('calculated_at', [$dates['start'], $dates['end']]);

        if ($cropType) {
            $snapshots->whereHas('cropCycle', function ($query) use ($cropType) {
                $query->where('crop_type', $cropType);
            });
        }

        $snapshotData = $snapshots->get();
        
        if ($snapshotData->isEmpty()) {
            throw new \Exception('No profitability data available for the specified period');
        }

        // Calculate aggregated metrics
        $metrics = static::aggregateMetrics($snapshotData);
        
        // Create or update benchmark
        $benchmark = static::updateOrCreate(
            [
                'farm_id' => $farmId,
                'benchmark_period' => $period,
                'crop_type' => $cropType
            ],
            array_merge($metrics, [
                'period_start' => $dates['start'],
                'period_end' => $dates['end']
            ])
        );

        // Calculate scores and analysis
        $benchmark->overall_score = $benchmark->calculateOverallScore();
        $analysis = $benchmark->identifyStrengthsAndWeaknesses();
        $benchmark->strength_areas = $analysis['strengths'];
        $benchmark->improvement_areas = $analysis['improvement_areas'];
        
        $benchmark->save();
        
        // Dispatch event
        event(new FarmPerformanceBenchmarkGenerated($benchmark));
        
        return $benchmark;
    }

    private static function parsePeriodDates(string $period): array
    {
        // Parse period string (e.g., 'Q1_2024', 'SEASON_2024_A')
        if (str_contains($period, 'Q')) {
            [$quarter, $year] = explode('_', $period);
            $quarterNum = (int) str_replace('Q', '', $quarter);
            
            $start = Carbon::create($year, ($quarterNum - 1) * 3 + 1, 1);
            $end = $start->copy()->addMonths(3)->subDay();
        } else {
            // Default to last 3 months if format not recognized
            $end = now();
            $start = $end->copy()->subMonths(3);
        }

        return ['start' => $start, 'end' => $end];
    }

    private static function aggregateMetrics($snapshots): array
    {
        $totalRevenue = $snapshots->sum('total_revenue');
        $totalCosts = $snapshots->sum('cost_of_production');
        $totalYield = $snapshots->sum('actual_yield');
        $cycleCount = $snapshots->count();

        return [
            'total_revenue' => $totalRevenue,
            'total_costs' => $totalCosts,
            'net_profit' => $totalRevenue - $totalCosts,
            'profit_margin' => $totalRevenue > 0 ? (($totalRevenue - $totalCosts) / $totalRevenue) * 100 : 0,
            'roi_percentage' => $totalCosts > 0 ? (($totalRevenue - $totalCosts) / $totalCosts) * 100 : 0,
            'average_yield_efficiency' => $snapshots->avg('yield_efficiency'),
            'cost_per_kg' => $totalYield > 0 ? $totalCosts / $totalYield : 0,
            'revenue_per_kg' => $totalYield > 0 ? $totalRevenue / $totalYield : 0,
            'crop_cycles_completed' => $cycleCount,
            'average_cycle_duration' => $snapshots->avg('crop_duration_days'),
            'labour_cost_ratio' => $snapshots->avg('labour_cost_percentage'),
            'input_cost_ratio' => $snapshots->avg('input_cost_percentage'),
            'transport_cost_ratio' => $snapshots->avg('transport_cost_percentage'),
            'price_realization' => $snapshots->avg('price_realization'),
            'labour_efficiency' => 100 - $snapshots->avg('labour_cost_percentage'), // Simplified
            'input_efficiency' => 100 - $snapshots->avg('input_cost_percentage'),   // Simplified
            'land_utilization' => 85, // Placeholder - would need actual calculation
            'sales_efficiency' => 80,  // Placeholder
            'sustainability_score' => 75, // Placeholder
            'innovation_score' => 70      // Placeholder
        ];
    }
}
