<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Carbon;

class CropTask extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'crop_cycle_id',
        'task_name',
        'task_type',
        'description',
        'scheduled_date',
        'scheduled_time',
        'completed_date',
        'completed_time',
        'status',
        'priority',
        'estimated_duration',
        'actual_duration',
        'assigned_to',
        'completion_notes',
        'requires_approval',
        'approved_by',
        'approved_at',
        'task_data',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'completed_date' => 'date',
        'requires_approval' => 'boolean',
        'approved_at' => 'datetime',
        'task_data' => 'array',
    ];

    // Relationships
    public function cropCycle()
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    // Scopes
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'scheduled')
                    ->where('scheduled_date', '<', now()->toDateString());
    }

    public function scopeDueToday($query)
    {
        return $query->where('status', 'scheduled')
                    ->where('scheduled_date', now()->toDateString());
    }

    public function scopeDueThisWeek($query)
    {
        return $query->where('status', 'scheduled')
                    ->whereBetween('scheduled_date', [
                        now()->startOfWeek(),
                        now()->endOfWeek()
                    ]);
    }

    public function scopeByTaskType($query, $taskType)
    {
        return $query->where('task_type', $taskType);
    }

    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeForCrop($query, $cropCycleId)
    {
        return $query->where('crop_cycle_id', $cropCycleId);
    }

    // Accessors
    public function getDaysUntilDueAttribute()
    {
        if (!$this->scheduled_date) return null;
        return now()->diffInDays($this->scheduled_date, false);
    }

    public function getIsOverdueAttribute()
    {
        return $this->status === 'scheduled' && $this->scheduled_date < now()->toDateString();
    }

    public function getIsDueTodayAttribute()
    {
        return $this->status === 'scheduled' && $this->scheduled_date === now()->toDateString();
    }

    public function getFormattedScheduledTimeAttribute()
    {
        return $this->scheduled_time ? Carbon::parse($this->scheduled_time)->format('H:i') : null;
    }

    public function getEstimatedCostAttribute()
    {
        if ($this->estimated_duration && $this->assigned_to) {
            $user = $this->assignedUser;
            $farmUser = $user->farms()->where('farm_id', $this->cropCycle->farm_id)->first();
            
            if ($farmUser && $farmUser->pivot->hourly_rate) {
                return ($this->estimated_duration / 60) * $farmUser->pivot->hourly_rate;
            }
        }
        
        return 0;
    }

    public function getEfficiencyRatingAttribute()
    {
        if (!$this->estimated_duration || !$this->actual_duration) return null;
        
        $efficiency = ($this->estimated_duration / $this->actual_duration) * 100;
        return min(100, round($efficiency, 1));
    }

    public function getStatusLabelAttribute()
    {
        return ucfirst(str_replace('_', ' ', $this->status));
    }

    public function getTaskTypeIconAttribute()
    {
        $icons = [
            'planting' => 'seedling',
            'pest_control' => 'bug',
            'watering' => 'droplets',
            'fertilizing' => 'sprout',
            'harvesting' => 'tractor',
        ];
        
        return $icons[$this->task_type] ?? 'clipboard-list';
    }

    // Business Logic Methods
    public function canStart()
    {
        if ($this->scheduled_date > now()->toDateString()) {
            return false;
        }
        
        return $this->status === 'scheduled';
    }

    public function start($startedBy = null)
    {
        if (!$this->canStart()) {
            throw new \Exception('Task cannot be started at this time');
        }
        
        $this->status = 'in_progress';
        
        if ($startedBy && !$this->assigned_to) {
            $this->assigned_to = $startedBy;
        }
        
        $this->save();
    }

    public function complete(array $completionData = [])
    {
        if ($this->status !== 'in_progress' && $this->status !== 'scheduled') {
            throw new \Exception('Only in-progress or scheduled tasks can be completed');
        }
        
        $this->fill($completionData);
        $this->status = 'completed';
        $this->completed_date = now()->toDateString();
        
        $this->save();
        
        // Update crop cycle status if this was a milestone task
        $this->updateCropCycleStatus();
    }

    protected function updateCropCycleStatus()
    {
        $crop = $this->cropCycle;
        
        // Update crop status based on completed milestones
        if ($this->task_name === 'Nursery Setup' && $this->status === 'completed') {
            $crop->updateStatus('nursery');
        } elseif ($this->task_name === 'Transplanting' && $this->status === 'completed') {
            $crop->updateStatus('transplanted');
        } elseif ($this->task_type === 'harvesting' && $this->status === 'completed') {
            $crop->updateStatus('harvest');
        }
    }

    public function cancel($reason = null)
    {
        $this->status = 'cancelled';
        $this->completion_notes = $reason ? "Cancelled: {$reason}" : 'Task cancelled';
        $this->save();
    }

    public function skip($reason = null)
    {
        $this->cancel($reason ? "Skipped: {$reason}" : 'Skipped');
    }

    public function reschedule($newDate, $reason = null)
    {
        $this->scheduled_date = $newDate;
        $this->completion_notes = ($this->completion_notes ? $this->completion_notes . "\n" : '') .
                                 "Rescheduled to {$newDate}" . ($reason ? ": {$reason}" : '');
        $this->save();
    }

    // Static utility methods
    public static function getTaskTypes()
    {
        return [
            'planting' => 'Planting',
            'watering' => 'Watering',
            'fertilizing' => 'Fertilizing',
            'weeding' => 'Weeding',
            'pest_control' => 'Pest Control',
            'disease_management' => 'Disease Management',
            'pruning' => 'Pruning',
            'harvesting' => 'Harvesting',
            'general' => 'General',
            'other' => 'Other',
        ];
    }

    public static function getCommonTaskNames()
    {
        return [
            'planting' => ['Nursery Setup', 'Transplanting', 'Direct Planting'],
            'weeding' => ['First Weeding', 'Routine Weeding'],
            'pest_control' => ['Pest Spraying', 'Disease Control', 'Insecticide Application', 'Organic Pest Control'],
            'watering' => ['Watering', 'Drip Irrigation', 'Sprinkler System', 'Manual Watering'],
            'fertilizing' => ['Fertilizer Application', 'Organic Manure', 'Top Dressing', 'Foliar Feeding'],
            'harvesting' => ['Harvesting', 'Post-Harvest Processing', 'Packaging', 'Storage'],
        ];
    }
}
