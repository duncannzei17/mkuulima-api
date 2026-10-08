<?php

namespace App\Events;

use App\Models\FieldObservation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FieldObservationResolved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FieldObservation $observation
    ) {}

    public function toKafka(): array
    {
        $resolutionTime = $this->observation->resolved_at && $this->observation->created_at 
            ? $this->observation->created_at->diffInHours($this->observation->resolved_at)
            : null;

        return [
            'event_type' => 'field_observation.resolved',
            'event_id' => $this->observation->id,
            'timestamp' => now()->toISOString(),
            'tenant_id' => $this->observation->farm->tenant_id ?? null,
            'observation_data' => [
                'id' => $this->observation->id,
                'crop_cycle_id' => $this->observation->crop_cycle_id,
                'observation_type' => $this->observation->observation_type,
                'severity' => $this->observation->severity,
                'was_critical' => $this->observation->is_critical,
                'resolved_by' => $this->observation->resolved_by,
                'resolved_at' => $this->observation->resolved_at?->toISOString(),
            ],
            'resolution_data' => [
                'resolution_notes' => $this->observation->resolution_notes,
                'resolution_time_hours' => $resolutionTime,
                'resolver_name' => $this->observation->resolvedBy->name ?? null,
            ],
            'season_impact' => [
                'original_health_impact' => $this->observation->season_health_impact,
                'adjusted_health_impact' => $this->observation->calculateSeasonHealthImpact(),
                'crop_cycle_id' => $this->observation->crop_cycle_id,
                'impact_reduction' => $this->calculateImpactReduction(),
            ],
            'performance_metrics' => [
                'was_resolved_quickly' => $resolutionTime ? $resolutionTime <= 24 : false,
                'severity_level' => $this->observation->severity,
                'observation_type' => $this->observation->observation_type,
                'estimated_cost_impact' => $this->observation->cost_impact_estimate,
            ],
            'workflow' => [
                'previous_status' => 'approved',
                'new_status' => 'resolved',
                'observation_date' => $this->observation->observation_date->toDateString(),
                'days_to_resolution' => $this->observation->observation_date->diffInDays($this->observation->resolved_at),
            ]
        ];
    }

    private function calculateImpactReduction(): float
    {
        // Resolution reduces the impact by 70% (configurable)
        $originalImpact = $this->observation->season_health_impact;
        $reductionFactor = 0.7;
        
        return abs($originalImpact * $reductionFactor);
    }
}