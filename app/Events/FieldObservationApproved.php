<?php

namespace App\Events;

use App\Models\FieldObservation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FieldObservationApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FieldObservation $observation
    ) {}

    public function toKafka(): array
    {
        return [
            'event_type' => 'field_observation.approved',
            'event_id' => $this->observation->id,
            'timestamp' => now()->toISOString(),
            'tenant_id' => $this->observation->farm->tenant_id ?? null,
            'observation_data' => [
                'id' => $this->observation->id,
                'crop_cycle_id' => $this->observation->crop_cycle_id,
                'observation_type' => $this->observation->observation_type,
                'severity' => $this->observation->severity,
                'is_critical' => $this->observation->is_critical,
                'approved_by' => $this->observation->approved_by,
                'approved_at' => $this->observation->approved_at?->toISOString(),
            ],
            'season_impact' => [
                'season_health_impact' => $this->observation->season_health_impact,
                'crop_cycle_id' => $this->observation->crop_cycle_id,
                'new_health_status' => $this->observation->cropCycle->health_status ?? null,
            ],
            'ai_insights' => [
                'insights' => $this->observation->ai_insights,
                'recommendations' => $this->observation->recommendations,
                'insights_count' => count($this->observation->ai_insights ?? []),
                'recommendations_count' => count($this->observation->recommendations ?? []),
            ],
            'workflow' => [
                'original_status' => 'pending',
                'new_status' => 'approved',
                'approver_name' => $this->observation->approvedBy->name ?? null,
                'auto_approved' => $this->observation->created_by === $this->observation->approved_by,
            ]
        ];
    }
}