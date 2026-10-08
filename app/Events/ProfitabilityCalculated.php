<?php

namespace App\Events;

use App\Models\ProfitabilitySnapshot;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProfitabilityCalculated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ProfitabilitySnapshot $snapshot
    ) {}

    public function toKafka(): array
    {
        return [
            'event_type' => 'profitability.calculated',
            'event_id' => $this->snapshot->id,
            'timestamp' => now()->toISOString(),
            'tenant_id' => $this->snapshot->farm->tenant_id ?? null,
            'farm_id' => $this->snapshot->farm_id,
            'crop_cycle_id' => $this->snapshot->crop_cycle_id,
            'profitability_data' => [
                'crop_type' => $this->snapshot->cropCycle->crop_type ?? 'unknown',
                'net_profit' => $this->snapshot->net_profit,
                'profit_margin' => $this->snapshot->profit_margin,
                'roi_percentage' => $this->snapshot->roi_percentage,
                'yield_efficiency' => $this->snapshot->yield_efficiency,
                'season_health_score' => $this->snapshot->season_health_score,
                'cost_of_production' => $this->snapshot->cost_of_production,
                'total_revenue' => $this->snapshot->total_revenue,
                'profit_per_kg' => $this->snapshot->profit_per_kg,
                'cost_per_kg' => $this->snapshot->cost_per_kg
            ],
            'cost_breakdown' => [
                'labour_cost' => $this->snapshot->labour_cost,
                'input_cost' => $this->snapshot->input_cost,
                'inventory_cost' => $this->snapshot->inventory_cost,
                'transport_cost' => $this->snapshot->transport_cost,
                'labour_percentage' => $this->snapshot->labour_cost_percentage,
                'input_percentage' => $this->snapshot->input_cost_percentage
            ],
            'performance_metrics' => [
                'efficiency_score' => $this->snapshot->efficiency_score,
                'cost_efficiency_score' => $this->snapshot->cost_efficiency_score,
                'yield_performance_score' => $this->snapshot->yield_performance_score,
                'actual_yield' => $this->snapshot->actual_yield,
                'expected_yield' => $this->snapshot->expected_yield
            ],
            'period_info' => [
                'cycle_start_date' => $this->snapshot->cycle_start_date,
                'cycle_end_date' => $this->snapshot->cycle_end_date,
                'crop_duration_days' => $this->snapshot->crop_duration_days
            ],
            'calculated_at' => $this->snapshot->calculated_at->toISOString()
        ];
    }
}