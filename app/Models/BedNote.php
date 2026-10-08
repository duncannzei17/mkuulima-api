<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Traits\HasUuids;

class BedNote extends Model
{
    use HasUuids;

    protected $fillable = [
        'bed_id', 'farm_id', 'note_date', 'note_content', 'note_type', 'severity',
        'requires_action', 'action_required_by', 'is_resolved', 'resolved_date',
        'resolution_notes', 'estimated_cost_impact', 'estimated_yield_impact',
        'affected_area_percentage', 'specific_location', 'crop_cycle_id',
        'days_after_planting', 'growth_stage', 'weather_conditions',
        'temperature', 'humidity', 'after_rain', 'during_irrigation',
        'photos', 'gps_latitude', 'gps_longitude', 'follow_up_task_id',
        'follow_up_date', 'recurring_issue', 'recurrence_count',
        'status', 'approved_by', 'approval_notes', 'created_by', 'updated_by'
    ];

    protected $casts = [
        'note_date' => 'date',
        'requires_action' => 'boolean',
        'action_required_by' => 'date',
        'is_resolved' => 'boolean',
        'resolved_date' => 'date',
        'estimated_cost_impact' => 'decimal:2',
        'estimated_yield_impact' => 'decimal:2',
        'temperature' => 'decimal:2',
        'humidity' => 'decimal:2',
        'after_rain' => 'boolean',
        'during_irrigation' => 'boolean',
        'photos' => 'array',
        'gps_latitude' => 'decimal:8',
        'gps_longitude' => 'decimal:8',
        'follow_up_date' => 'datetime',
        'recurring_issue' => 'boolean',
        'weather_conditions' => 'array',
        'approved_at' => 'datetime'
    ];

    // Note types configuration
    public const NOTE_TYPES = [
        'general' => 'General Observation',
        'soil_issue' => 'Soil Issue',
        'drainage_problem' => 'Drainage Problem',
        'pest_observation' => 'Pest Observation',
        'disease_observation' => 'Disease Observation',
        'nutrient_deficiency' => 'Nutrient Deficiency',
        'water_stress' => 'Water Stress',
        'maintenance_required' => 'Maintenance Required',
        'improvement_suggestion' => 'Improvement Suggestion',
        'weather_damage' => 'Weather Damage',
        'equipment_issue' => 'Equipment Issue',
        'harvest_quality' => 'Harvest Quality',
        'yield_observation' => 'Yield Observation'
    ];

    // Severity levels configuration
    public const SEVERITIES = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical'
    ];

    // Status configuration
    public const STATUSES = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'rejected' => 'Rejected'
    ];

    // Growth stages
    public const GROWTH_STAGES = [
        'seedling' => 'Seedling',
        'vegetative' => 'Vegetative',
        'flowering' => 'Flowering',
        'fruiting' => 'Fruiting',
        'maturity' => 'Maturity',
        'harvest' => 'Harvest'
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

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function followUpTask(): BelongsTo
    {
        return $this->belongsTo(CropTask::class, 'follow_up_task_id');
    }

    // Scopes
    public function scopeForBed($query, $bedId)
    {
        return $query->where('bed_id', $bedId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('note_type', $type);
    }

    public function scopeBySeverity($query, $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeRequiringAction($query)
    {
        return $query->where('requires_action', true)->where('is_resolved', false);
    }

    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('note_date', '>=', now()->subDays($days));
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical');
    }

    public function scopeRecurring($query)
    {
        return $query->where('recurring_issue', true);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', 'submitted');
    }

    // Mutators and Accessors
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($note) {
            // Auto-detect recurring issues
            $note->detectRecurringIssue();
            
            // Auto-calculate impact if not provided
            if (!$note->estimated_cost_impact) {
                $note->estimated_cost_impact = $note->calculateEstimatedCostImpact();
            }
            
            if (!$note->estimated_yield_impact) {
                $note->estimated_yield_impact = $note->calculateEstimatedYieldImpact();
            }
        });

        static::created(function ($note) {
            // Update bed health score when critical note is added
            if (in_array($note->severity, ['high', 'critical'])) {
                $note->bed->calculateHealthScore();
            }

            // Update bed status if critical issue detected
            if ($note->severity === 'critical') {
                $note->bed->update(['status' => 'issues_detected']);
            }

            // Create follow-up task if action required
            if ($note->requires_action && $note->action_required_by) {
                $note->createFollowUpTask();
            }

            Log::info('Bed note created', [
                'note_id' => $note->id,
                'bed_id' => $note->bed_id,
                'severity' => $note->severity,
                'type' => $note->note_type
            ]);
        });

        static::updated(function ($note) {
            // Update bed health when note is resolved
            if ($note->isDirty('is_resolved') && $note->is_resolved) {
                $note->bed->calculateHealthScore();
                $note->updateBedStatusAfterResolution();
            }
        });
    }

    // Business Logic Methods

    /**
     * Approve the note
     */
    public function approve(string $approvalNotes = ''): bool
    {
        return $this->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'approval_notes' => $approvalNotes
        ]);
    }

    /**
     * Reject the note
     */
    public function reject(string $rejectionReason): bool
    {
        return $this->update([
            'status' => 'rejected',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'approval_notes' => $rejectionReason
        ]);
    }

    /**
     * Resolve the issue
     */
    public function resolve(string $resolutionNotes = ''): bool
    {
        $result = $this->update([
            'is_resolved' => true,
            'resolved_date' => now(),
            'resolution_notes' => $resolutionNotes
        ]);

        if ($result) {
            // Update bed health score
            $this->bed->calculateHealthScore();
            
            // Check if bed status should be updated
            $this->updateBedStatusAfterResolution();

            Log::info('Bed note resolved', [
                'note_id' => $this->id,
                'bed_id' => $this->bed_id,
                'resolution_notes' => $resolutionNotes
            ]);
        }

        return $result;
    }

    /**
     * Calculate estimated cost impact based on severity and type
     */
    public function calculateEstimatedCostImpact(): float
    {
        $baseImpact = match($this->severity) {
            'critical' => 1000,
            'high' => 500,
            'medium' => 200,
            'low' => 50,
            default => 0
        };

        $typeMultiplier = match($this->note_type) {
            'pest_observation', 'disease_observation' => 2.0,
            'soil_issue', 'drainage_problem' => 1.5,
            'equipment_issue', 'maintenance_required' => 1.3,
            'water_stress', 'nutrient_deficiency' => 1.2,
            'weather_damage' => 1.8,
            default => 1.0
        };

        $areaFactor = $this->affected_area_percentage / 100;

        return $baseImpact * $typeMultiplier * $areaFactor;
    }

    /**
     * Calculate estimated yield impact
     */
    public function calculateEstimatedYieldImpact(): float
    {
        // Get bed's average yield per cycle
        $avgYield = $this->bed->average_yield_per_cycle ?? 100;

        $impactPercentage = match($this->severity) {
            'critical' => 0.40, // 40% yield loss
            'high' => 0.20,     // 20% yield loss
            'medium' => 0.10,   // 10% yield loss
            'low' => 0.02,      // 2% yield loss
            default => 0
        };

        $typeMultiplier = match($this->note_type) {
            'pest_observation', 'disease_observation' => 1.5,
            'water_stress', 'nutrient_deficiency' => 1.2,
            'soil_issue' => 1.1,
            'weather_damage' => 1.3,
            default => 1.0
        };

        $areaFactor = $this->affected_area_percentage / 100;

        return $avgYield * $impactPercentage * $typeMultiplier * $areaFactor;
    }

    /**
     * Detect if this is a recurring issue
     */
    public function detectRecurringIssue(): void
    {
        $similarNotes = static::where('bed_id', $this->bed_id)
            ->where('note_type', $this->note_type)
            ->where('note_date', '>=', now()->subMonths(6))
            ->count();

        if ($similarNotes >= 2) {
            $this->recurring_issue = true;
            $this->recurrence_count = $similarNotes + 1;
        }
    }

    /**
     * Create follow-up task if action is required
     */
    public function createFollowUpTask(): ?CropTask
    {
        if (!$this->requires_action || !$this->crop_cycle_id) {
            return null;
        }

        $taskData = [
            'crop_cycle_id' => $this->crop_cycle_id,
            'task_name' => $this->generateFollowUpTaskName(),
            'task_description' => "Follow-up action for bed note: {$this->note_content}",
            'task_type' => $this->getFollowUpTaskType(),
            'planned_date' => $this->action_required_by ?? now()->addDays(3)->toDateString(),
            'estimated_duration_hours' => $this->getEstimatedTaskDuration(),
            'priority' => $this->mapSeverityToPriority(),
            'status' => 'pending',
            'bed_id' => $this->bed_id,
            'created_by' => $this->created_by
        ];

        $task = CropTask::create($taskData);

        $this->update(['follow_up_task_id' => $task->id]);

        return $task;
    }

    /**
     * Update bed status after issue resolution
     */
    private function updateBedStatusAfterResolution(): void
    {
        // Check if there are any remaining unresolved critical issues
        $criticalIssues = $this->bed->notes()
            ->where('severity', 'critical')
            ->where('is_resolved', false)
            ->count();

        if ($criticalIssues === 0 && $this->bed->status === 'issues_detected') {
            // No more critical issues, restore appropriate status
            $newStatus = $this->bed->currentCropAssignment ? 'planted' : 'empty';
            $this->bed->update(['status' => $newStatus]);
        }
    }

    /**
     * Generate appropriate task name for follow-up
     */
    private function generateFollowUpTaskName(): string
    {
        return match($this->note_type) {
            'pest_observation' => 'Address Pest Issue',
            'disease_observation' => 'Treat Plant Disease',
            'soil_issue' => 'Soil Treatment',
            'drainage_problem' => 'Fix Drainage',
            'nutrient_deficiency' => 'Apply Fertilizer',
            'water_stress' => 'Adjust Irrigation',
            'maintenance_required' => 'Bed Maintenance',
            'equipment_issue' => 'Equipment Repair',
            default => 'Address Bed Issue'
        } . " - Bed {$this->bed->name}";
    }

    /**
     * Get appropriate task type for follow-up
     */
    private function getFollowUpTaskType(): string
    {
        return match($this->note_type) {
            'pest_observation', 'disease_observation' => 'pest_control',
            'soil_issue', 'nutrient_deficiency' => 'soil_management',
            'water_stress' => 'irrigation',
            'maintenance_required', 'equipment_issue' => 'maintenance',
            default => 'general'
        };
    }

    /**
     * Estimate task duration based on severity and type
     */
    private function getEstimatedTaskDuration(): float
    {
        $baseDuration = match($this->severity) {
            'critical' => 4,
            'high' => 2,
            'medium' => 1,
            'low' => 0.5,
            default => 1
        };

        $typeMultiplier = match($this->note_type) {
            'equipment_issue', 'maintenance_required' => 2.0,
            'soil_issue', 'drainage_problem' => 1.5,
            'pest_observation', 'disease_observation' => 1.2,
            default => 1.0
        };

        return $baseDuration * $typeMultiplier;
    }

    /**
     * Map severity to task priority
     */
    private function mapSeverityToPriority(): string
    {
        return match($this->severity) {
            'critical' => 'urgent',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            default => 'medium'
        };
    }

    /**
     * Get recommendation for addressing this note
     */
    public function getRecommendation(): array
    {
        $recommendations = [
            'pest_observation' => [
                'action' => 'Implement integrated pest management',
                'timeline' => 'Immediate',
                'resources' => ['Pesticides', 'Beneficial insects', 'Traps']
            ],
            'disease_observation' => [
                'action' => 'Apply fungicide and improve air circulation',
                'timeline' => 'Within 24 hours',
                'resources' => ['Fungicide', 'Pruning tools']
            ],
            'soil_issue' => [
                'action' => 'Conduct soil test and apply amendments',
                'timeline' => 'Within 1 week',
                'resources' => ['Soil amendments', 'Compost', 'Lime/Sulfur']
            ],
            'drainage_problem' => [
                'action' => 'Improve drainage system',
                'timeline' => 'Within 2 weeks',
                'resources' => ['Drainage pipes', 'Gravel', 'Labor']
            ],
            'water_stress' => [
                'action' => 'Adjust irrigation schedule',
                'timeline' => 'Immediate',
                'resources' => ['Water', 'Irrigation system check']
            ],
            'nutrient_deficiency' => [
                'action' => 'Apply targeted fertilization',
                'timeline' => 'Within 3 days',
                'resources' => ['Specific fertilizers', 'Foliar feed']
            ]
        ];

        return $recommendations[$this->note_type] ?? [
            'action' => 'Review and address noted issue',
            'timeline' => 'As needed',
            'resources' => ['Assessment', 'Appropriate materials']
        ];
    }

    /**
     * Get similar notes for pattern analysis
     */
    public function getSimilarNotes(int $months = 12)
    {
        return static::where('bed_id', $this->bed_id)
            ->where('note_type', $this->note_type)
            ->where('id', '!=', $this->id)
            ->where('note_date', '>=', now()->subMonths($months))
            ->orderBy('note_date', 'desc')
            ->get();
    }

    /**
     * Check if note is overdue for action
     */
    public function isOverdue(): bool
    {
        return $this->requires_action && 
               $this->action_required_by && 
               now()->isAfter($this->action_required_by) && 
               !$this->is_resolved;
    }

    /**
     * Get days until action required or overdue
     */
    public function getDaysUntilAction(): ?int
    {
        if (!$this->requires_action || !$this->action_required_by) {
            return null;
        }

        return now()->diffInDays($this->action_required_by, false);
    }
}