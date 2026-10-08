<?php

namespace App\Events;

use App\Models\FieldObservation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FieldObservationCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FieldObservation $observation
    ) {}

    public function toKafka(): array
    {
        return [
            'event_type' => 'field_observation.created',
            'event_id' => $this->observation->id,
            'timestamp' => now()->toISOString(),
            'tenant_id' => $this->observation->farm->tenant_id ?? null,
            'observation_data' => [
                'id' => $this->observation->id,
                'crop_cycle_id' => $this->observation->crop_cycle_id,
                'bed_id' => $this->observation->bed_id,
                'observation_date' => $this->observation->observation_date->toDateString(),
                'observation_type' => $this->observation->observation_type,
                'severity' => $this->observation->severity,
                'is_critical' => $this->observation->is_critical,
                'requires_immediate_action' => $this->observation->requires_immediate_action,
                'status' => $this->observation->status,
                'created_by' => $this->observation->created_by,
                'worker_id' => $this->observation->worker_id,
            ],
            'context' => [
                'crop_name' => $this->observation->cropCycle->crop_name ?? null,
                'bed_name' => $this->observation->bed->name ?? null,
                'worker_name' => $this->observation->worker->name ?? null,
                'creator_name' => $this->observation->createdBy->name ?? null,
            ],
            'impact_assessment' => [
                'estimated_impact_percentage' => $this->observation->estimated_impact_percentage,
                'affected_area' => $this->observation->affected_area,
                'affected_area_unit' => $this->observation->affected_area_unit,
                'season_health_impact' => $this->observation->season_health_impact,
            ],
            'location_data' => [
                'latitude' => $this->observation->latitude,
                'longitude' => $this->observation->longitude,
                'location_notes' => $this->observation->location_notes,
            ],
            'environmental_data' => [
                'weather_conditions' => $this->observation->weather_conditions,
                'temperature' => $this->observation->temperature,
                'humidity' => $this->observation->humidity,
            ],
            'ai_insights' => [
                'insights_generated' => !empty($this->observation->ai_insights),
                'recommendations_count' => count($this->observation->recommendations ?? []),
                'insights' => $this->observation->ai_insights,
                'recommendations' => $this->observation->recommendations,
            ]
        ];
    }
}