<?php

namespace App\Events;

use App\Models\FarmPerformanceBenchmark;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FarmPerformanceBenchmarkGenerated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FarmPerformanceBenchmark $benchmark
    ) {}

    public function toKafka(): array
    {
        return [
            'event_type' => 'profitability.benchmark_generated',
            'event_id' => $this->benchmark->id,
            'timestamp' => now()->toISOString(),
            'tenant_id' => $this->benchmark->farm->tenant_id ?? null,
            'farm_id' => $this->benchmark->farm_id,
            'benchmark_data' => [
                'period' => $this->benchmark->benchmark_period,
                'crop_type' => $this->benchmark->crop_type,
                'period_start' => $this->benchmark->period_start->toDateString(),
                'period_end' => $this->benchmark->period_end->toDateString(),
                'overall_score' => $this->benchmark->overall_score,
                'performance_grade' => $this->calculatePerformanceGrade($this->benchmark->overall_score)
            ],
            'financial_metrics' => [
                'total_revenue' => $this->benchmark->total_revenue,
                'total_costs' => $this->benchmark->total_costs,
                'net_profit' => $this->benchmark->net_profit,
                'profit_margin' => $this->benchmark->profit_margin,
                'roi_percentage' => $this->benchmark->roi_percentage
            ],
            'efficiency_metrics' => [
                'average_yield_efficiency' => $this->benchmark->average_yield_efficiency,
                'cost_per_kg' => $this->benchmark->cost_per_kg,
                'revenue_per_kg' => $this->benchmark->revenue_per_kg,
                'labour_efficiency' => $this->benchmark->labour_efficiency,
                'input_efficiency' => $this->benchmark->input_efficiency,
                'land_utilization' => $this->benchmark->land_utilization
            ],
            'operational_metrics' => [
                'crop_cycles_completed' => $this->benchmark->crop_cycles_completed,
                'average_cycle_duration' => $this->benchmark->average_cycle_duration,
                'buyer_relationships_count' => $this->benchmark->buyer_relationships_count
            ],
            'cost_analysis' => [
                'labour_cost_ratio' => $this->benchmark->labour_cost_ratio,
                'input_cost_ratio' => $this->benchmark->input_cost_ratio,
                'transport_cost_ratio' => $this->benchmark->transport_cost_ratio,
                'overhead_cost_ratio' => $this->benchmark->overhead_cost_ratio
            ],
            'quality_scores' => [
                'sustainability_score' => $this->benchmark->sustainability_score,
                'innovation_score' => $this->benchmark->innovation_score,
                'price_realization' => $this->benchmark->price_realization,
                'sales_efficiency' => $this->benchmark->sales_efficiency
            ],
            'comparative_metrics' => [
                'industry_percentile' => $this->benchmark->industry_percentile,
                'regional_percentile' => $this->benchmark->regional_percentile,
                'above_industry_average' => $this->benchmark->above_industry_average,
                'period_over_period_growth' => $this->benchmark->period_over_period_growth
            ],
            'performance_analysis' => [
                'strength_areas' => $this->benchmark->strength_areas,
                'improvement_areas' => $this->benchmark->improvement_areas,
                'recommendations_count' => count($this->benchmark->generateRecommendations())
            ],
            'farm_info' => [
                'farm_name' => $this->benchmark->farm->name ?? 'Unknown',
                'farm_type' => $this->benchmark->farm->farm_type ?? 'Unknown',
                'farm_size' => $this->benchmark->farm->total_area ?? 0
            ]
        ];
    }

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
}