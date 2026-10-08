<?php

namespace App\Events;

use App\Models\ProfitabilityInsight;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProfitabilityInsightGenerated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ProfitabilityInsight $insight
    ) {}

    public function toKafka(): array
    {
        return [
            'event_type' => 'profitability.insight_generated',
            'event_id' => $this->insight->id,
            'timestamp' => now()->toISOString(),
            'tenant_id' => $this->insight->farm->tenant_id ?? null,
            'farm_id' => $this->insight->farm_id,
            'crop_cycle_id' => $this->insight->crop_cycle_id,
            'insight_data' => [
                'type' => $this->insight->insight_type,
                'category' => $this->insight->category,
                'priority' => $this->insight->priority,
                'title' => $this->insight->title,
                'message' => $this->insight->message,
                'recommendation' => $this->insight->recommendation,
                'is_actionable' => $this->insight->is_actionable,
                'confidence_score' => $this->insight->confidence_score
            ],
            'impact_analysis' => [
                'potential_impact_amount' => $this->insight->potential_impact_amount,
                'impact_type' => $this->insight->impact_type,
                'action_deadline' => $this->insight->action_deadline?->toISOString()
            ],
            'context' => [
                'crop_type' => $this->insight->crop_type,
                'season' => $this->insight->season,
                'data_points' => $this->insight->data_points,
                'suggested_actions' => $this->insight->suggested_actions
            ],
            'farm_info' => [
                'farm_name' => $this->insight->farm->name ?? 'Unknown',
                'farm_type' => $this->insight->farm->farm_type ?? 'Unknown'
            ]
        ];
    }
}