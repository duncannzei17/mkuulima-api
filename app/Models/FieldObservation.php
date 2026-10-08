<?php

namespace App\Models;

use App\Traits\HasUuids;
use App\Traits\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldObservation extends Model
{
    use HasFactory, HasUuids, TenantScope;

    protected $fillable = [
        'crop_cycle_id',
        'bed_id',
        'worker_id',
        'created_by',
        'observation_date',
        'observation_type',
        'severity',
        'description',
        'location_notes',
        'photo_urls',
        'latitude',
        'longitude',
        'weather_conditions',
        'temperature',
        'humidity',
        'status',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'is_critical',
        'requires_immediate_action',
        'estimated_impact_percentage',
        'affected_area',
        'affected_area_unit',
        'is_resolved',
        'resolved_at',
        'resolution_notes',
        'resolved_by',
        'follow_up_tasks',
        'season_health_impact',
        'yield_impact_estimate',
        'cost_impact_estimate',
        'ai_insights',
        'recommendations',
        'related_task_id',
        'related_expense_id',
        'growth_stage',
        'days_after_planting',
    ];

    protected $casts = [
        'observation_date' => 'date',
        'photo_urls' => 'array',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'temperature' => 'decimal:1',
        'humidity' => 'decimal:2',
        'approved_at' => 'datetime',
        'is_critical' => 'boolean',
        'requires_immediate_action' => 'boolean',
        'estimated_impact_percentage' => 'decimal:2',
        'affected_area' => 'decimal:2',
        'is_resolved' => 'boolean',
        'resolved_at' => 'datetime',
        'follow_up_tasks' => 'array',
        'season_health_impact' => 'integer',
        'yield_impact_estimate' => 'decimal:2',
        'cost_impact_estimate' => 'decimal:2',
        'ai_insights' => 'array',
        'recommendations' => 'array',
        'days_after_planting' => 'integer',
    ];

    public const OBSERVATION_TYPES = [
        'pest' => 'Pest',
        'disease' => 'Disease',
        'weather_impact' => 'Weather Impact',
        'nutrient_deficiency' => 'Nutrient Deficiency',
        'water_stress' => 'Water Stress',
        'physical_damage' => 'Physical Damage',
        'growth_issues' => 'Growth Issues',
        'labour_related' => 'Labour Related',
        'market_issue' => 'Market Issue',
        'soil_condition' => 'Soil Condition',
        'equipment_issue' => 'Equipment Issue',
        'other' => 'Other',
    ];

    public const SEVERITY_LEVELS = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'resolved' => 'Resolved',
    ];

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(ObservationTag::class, 'observation_id');
    }

    public function relatedTask(): BelongsTo
    {
        return $this->belongsTo(CropTask::class, 'related_task_id');
    }

    public function relatedExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'related_expense_id');
    }

    public function approve(User $approver): bool
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        $this->updateSeasonHealthScore();
        $this->generateAIInsights();

        return true;
    }

    public function reject(User $rejector, string $reason): bool
    {
        $this->update([
            'status' => 'rejected',
            'approved_by' => $rejector->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return true;
    }

    public function resolve(User $resolver, string $notes = null): bool
    {
        $this->update([
            'is_resolved' => true,
            'resolved_at' => now(),
            'resolved_by' => $resolver->id,
            'resolution_notes' => $notes,
        ]);

        $this->updateSeasonHealthScore();

        return true;
    }

    public function syncBedHealthScore(): void
    {
        if (!$this->bed_id) {
            return;
        }

        $bed = Bed::find($this->bed_id);
        if (!$bed) {
            return;
        }

        $impact = static::query()
            ->where('bed_id', $this->bed_id)
            ->where('status', 'approved')
            ->get()
            ->sum(fn (self $observation) => $observation->calculateSeasonHealthImpact());

        $bed->update([
            'health_score' => max(0, min(100, 100 + $impact)),
            'health_score_updated_at' => now(),
        ]);
    }

    public function calculateSeasonHealthImpact(): int
    {
        $baseImpact = match($this->severity) {
            'low' => -5,
            'medium' => -15,
            'high' => -30,
            default => 0,
        };

        $typeMultiplier = match($this->observation_type) {
            'pest', 'disease' => 1.5,
            'weather_impact', 'water_stress' => 1.2,
            'nutrient_deficiency', 'growth_issues' => 1.0,
            default => 0.8,
        };

        $criticalMultiplier = $this->is_critical ? 1.5 : 1.0;
        $resolvedMultiplier = $this->is_resolved ? 0.3 : 1.0;

        return (int) round($baseImpact * $typeMultiplier * $criticalMultiplier * $resolvedMultiplier);
    }

    public function updateSeasonHealthScore(): void
    {
        $this->season_health_impact = $this->calculateSeasonHealthImpact();
        $this->save();

        if ($this->crop_cycle_id && $this->cropCycle) {
            $this->cropCycle->recalculateSeasonHealthScore();
        }

        $this->syncBedHealthScore();
    }

    public function generateAIInsights(): array
    {
        $insights = [];
        
        if ($this->severity === 'high') {
            $insights[] = $this->generateHighSeverityInsight();
        }

        if ($this->observation_type === 'pest') {
            $insights[] = $this->generatePestInsight();
        }

        if ($this->observation_type === 'disease') {
            $insights[] = $this->generateDiseaseInsight();
        }

        if ($this->observation_type === 'water_stress') {
            $insights[] = $this->generateWaterStressInsight();
        }

        $this->ai_insights = array_filter($insights);
        $this->recommendations = $this->generateRecommendations();
        $this->save();

        return $this->ai_insights;
    }

    private function generateHighSeverityInsight(): string
    {
        return "High severity {$this->observation_type} detected. Immediate intervention recommended to prevent yield loss.";
    }

    private function generatePestInsight(): string
    {
        return "Pest pressure identified. Consider integrated pest management approach including natural predators and targeted treatments.";
    }

    private function generateDiseaseInsight(): string
    {
        return "Disease symptoms observed. Early intervention with appropriate fungicides or biocontrol agents recommended.";
    }

    private function generateWaterStressInsight(): string
    {
        return "Water stress detected. Adjust irrigation schedule or investigate drainage issues to optimize water management.";
    }

    public function generateRecommendations(): array
    {
        $recommendations = [];

        if ($this->severity === 'high' && !$this->is_resolved) {
            $recommendations[] = [
                'type' => 'immediate_action',
                'description' => 'Schedule immediate field inspection and corrective action',
                'priority' => 'urgent',
                'estimated_cost' => $this->estimateActionCost(),
            ];
        }

        if ($this->observation_type === 'pest') {
            $recommendations[] = [
                'type' => 'pest_management',
                'description' => 'Apply integrated pest management strategy',
                'priority' => $this->severity === 'high' ? 'urgent' : 'high',
                'estimated_cost' => 500.00,
            ];
        }

        if ($this->observation_type === 'disease') {
            $recommendations[] = [
                'type' => 'disease_control',
                'description' => 'Apply preventive fungicide treatment',
                'priority' => $this->severity === 'high' ? 'urgent' : 'high',
                'estimated_cost' => 300.00,
            ];
        }

        if ($this->affected_area > 100) {
            $recommendations[] = [
                'type' => 'area_treatment',
                'description' => 'Large area affected - consider zone-based treatment approach',
                'priority' => 'medium',
                'estimated_cost' => $this->affected_area * 5.00,
            ];
        }

        return $recommendations;
    }

    private function estimateActionCost(): float
    {
        $baseCost = match($this->observation_type) {
            'pest' => 500.00,
            'disease' => 300.00,
            'water_stress' => 200.00,
            'nutrient_deficiency' => 150.00,
            default => 100.00,
        };

        $areaMultiplier = $this->affected_area ? ($this->affected_area / 100) : 1.0;
        $severityMultiplier = match($this->severity) {
            'high' => 1.5,
            'medium' => 1.0,
            'low' => 0.7,
            default => 1.0,
        };

        return $baseCost * $areaMultiplier * $severityMultiplier;
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('observation_type', $type);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeCritical($query)
    {
        return $query->where('is_critical', true);
    }

    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeForCropCycle($query, string $cropCycleId)
    {
        return $query->where('crop_cycle_id', $cropCycleId);
    }

    public function scopeForDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('observation_date', [$startDate, $endDate]);
    }

    public function getObservationTypeDisplayAttribute(): string
    {
        return self::OBSERVATION_TYPES[$this->observation_type] ?? $this->observation_type;
    }

    public function getSeverityDisplayAttribute(): string
    {
        return self::SEVERITY_LEVELS[$this->severity] ?? $this->severity;
    }

    public function getStatusDisplayAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
