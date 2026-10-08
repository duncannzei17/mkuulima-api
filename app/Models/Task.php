<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'crop_id',
        'plot_id',
        'assigned_to',
        'assigned_by',
        'title',
        'description',
        'category',
        'priority',
        'status',
        'due_date',
        'estimated_hours',
        'actual_hours',
        'completion_percentage',
        'instructions',
        'required_tools',
        'required_materials',
        'location_details',
        'weather_dependent',
        'min_workers',
        'max_workers',
        'skill_level_required',
        'safety_requirements',
        'started_at',
        'completed_at',
        'notes',
        'attachments',
        'recurring',
        'recurring_pattern',
        'parent_task_id',
        'metadata'
    ];

    protected $casts = [
        'due_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'completion_percentage' => 'integer',
        'min_workers' => 'integer',
        'max_workers' => 'integer',
        'weather_dependent' => 'boolean',
        'recurring' => 'boolean',
        'required_tools' => 'array',
        'required_materials' => 'array',
        'safety_requirements' => 'array',
        'attachments' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'pending',
        'priority' => 'medium',
        'completion_percentage' => 0,
        'weather_dependent' => false,
        'recurring' => false,
        'min_workers' => 1,
        'max_workers' => 1,
    ];

    // Task categories (same as LabourSession)
    public const CATEGORIES = [
        'land_preparation' => 'Land Preparation',
        'planting' => 'Planting & Seeding',
        'weeding' => 'Weeding',
        'fertilizing' => 'Fertilizing',
        'irrigation' => 'Irrigation',
        'spraying' => 'Spraying/Pest Control',
        'pruning' => 'Pruning & Training',
        'thinning' => 'Thinning',
        'harvesting' => 'Harvesting',
        'post_harvest' => 'Post-harvest Processing',
        'transport' => 'Transport & Loading',
        'maintenance' => 'Equipment Maintenance',
        'construction' => 'Infrastructure Construction',
        'supervision' => 'Supervision',
        'planning' => 'Planning & Assessment',
        'other' => 'Other Tasks'
    ];

    // Task priorities
    public const PRIORITIES = [
        'low' => 'Low Priority',
        'medium' => 'Medium Priority',
        'high' => 'High Priority',
        'urgent' => 'Urgent',
        'critical' => 'Critical'
    ];

    // Task statuses
    public const STATUSES = [
        'pending' => 'Pending',
        'assigned' => 'Assigned',
        'in_progress' => 'In Progress',
        'on_hold' => 'On Hold',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'overdue' => 'Overdue'
    ];

    // Skill levels
    public const SKILL_LEVELS = [
        'beginner' => 'Beginner',
        'intermediate' => 'Intermediate', 
        'experienced' => 'Experienced',
        'expert' => 'Expert/Specialist'
    ];

    // Recurring patterns
    public const RECURRING_PATTERNS = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'biweekly' => 'Bi-weekly',
        'monthly' => 'Monthly',
        'seasonal' => 'Seasonal',
        'custom' => 'Custom'
    ];

    // Relationships
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function crop()
    {
        return $this->belongsTo(Crop::class);
    }

    public function plot()
    {
        return $this->belongsTo(Plot::class);
    }

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function parentTask()
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subTasks()
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function labourSessions()
    {
        return $this->hasMany(LabourSession::class);
    }

    // Scopes
    public function scopeByFarm($query, $farmId)
    {
        return $query->where('farm_id', $farmId);
    }

    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
                    ->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function scopeDueToday($query)
    {
        return $query->whereDate('due_date', now()->toDateString());
    }

    public function scopeDueThisWeek($query)
    {
        return $query->whereBetween('due_date', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', ['high', 'urgent', 'critical']);
    }

    public function scopeWeatherDependent($query)
    {
        return $query->where('weather_dependent', true);
    }

    public function scopeRecurring($query)
    {
        return $query->where('recurring', true);
    }

    // Accessors & Mutators
    public function getCategoryNameAttribute()
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getPriorityNameAttribute()
    {
        return self::PRIORITIES[$this->priority] ?? $this->priority;
    }

    public function getStatusNameAttribute()
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getSkillLevelNameAttribute()
    {
        return self::SKILL_LEVELS[$this->skill_level_required] ?? $this->skill_level_required;
    }

    public function getIsOverdueAttribute()
    {
        return $this->due_date && $this->due_date->isPast() && !in_array($this->status, ['completed', 'cancelled']);
    }

    public function getDaysUntilDueAttribute()
    {
        return $this->due_date ? now()->diffInDays($this->due_date, false) : null;
    }

    public function getDurationAttribute()
    {
        if ($this->started_at && $this->completed_at) {
            return $this->started_at->diffInHours($this->completed_at);
        }
        return null;
    }

    public function getProgressStatusAttribute()
    {
        if ($this->status === 'completed') {
            return 'completed';
        } elseif ($this->is_overdue) {
            return 'overdue';
        } elseif ($this->due_date && $this->due_date->isToday()) {
            return 'due_today';
        } elseif ($this->status === 'in_progress') {
            return 'in_progress';
        }
        return 'pending';
    }

    // Business Logic Methods
    public function start($userId = null)
    {
        $this->status = 'in_progress';
        $this->started_at = now();
        
        if ($userId) {
            $this->assigned_to = $userId;
        }
        
        $this->metadata = array_merge($this->metadata ?? [], [
            'started_by' => $userId,
        ]);
        
        $this->save();
    }

    public function complete($userId = null, $notes = null)
    {
        $this->status = 'completed';
        $this->completed_at = now();
        $this->completion_percentage = 100;
        
        if ($notes) {
            $this->notes = $notes;
        }
        
        if ($this->started_at) {
            $this->actual_hours = $this->started_at->diffInHours($this->completed_at);
        }
        
        $this->metadata = array_merge($this->metadata ?? [], [
            'completed_by' => $userId,
            'completion_notes' => $notes,
        ]);
        
        $this->save();
        
        // Create next occurrence if recurring
        if ($this->recurring) {
            $this->createNextOccurrence();
        }
    }

    public function updateProgress($percentage, $notes = null)
    {
        $this->completion_percentage = max(0, min(100, $percentage));
        
        if ($notes) {
            $this->notes = $notes;
        }
        
        // Update status based on progress
        if ($percentage == 0) {
            $this->status = 'pending';
        } elseif ($percentage >= 100) {
            $this->complete();
            return;
        } else {
            $this->status = 'in_progress';
        }
        
        $this->save();
    }

    public function assign($userId, $assignedById)
    {
        $this->assigned_to = $userId;
        $this->status = 'assigned';
        
        $this->metadata = array_merge($this->metadata ?? [], [
            'assigned_by' => $assignedById,
            'assigned_at' => now()->toISOString(),
        ]);
        
        $this->save();
    }

    public function cancel($reason = null, $userId = null)
    {
        $this->status = 'cancelled';
        
        $this->metadata = array_merge($this->metadata ?? [], [
            'cancelled_by' => $userId,
            'cancelled_at' => now()->toISOString(),
            'cancellation_reason' => $reason,
        ]);
        
        $this->save();
    }

    public function putOnHold($reason = null, $userId = null)
    {
        $this->status = 'on_hold';
        
        $this->metadata = array_merge($this->metadata ?? [], [
            'put_on_hold_by' => $userId,
            'put_on_hold_at' => now()->toISOString(),
            'hold_reason' => $reason,
        ]);
        
        $this->save();
    }

    public function resume($userId = null)
    {
        $this->status = $this->started_at ? 'in_progress' : 'assigned';
        
        $this->metadata = array_merge($this->metadata ?? [], [
            'resumed_by' => $userId,
            'resumed_at' => now()->toISOString(),
        ]);
        
        $this->save();
    }

    private function createNextOccurrence()
    {
        if (!$this->recurring || !$this->recurring_pattern) {
            return;
        }

        $nextDueDate = $this->calculateNextDueDate();
        
        if ($nextDueDate) {
            $nextTask = $this->replicate();
            $nextTask->status = 'pending';
            $nextTask->due_date = $nextDueDate;
            $nextTask->completion_percentage = 0;
            $nextTask->started_at = null;
            $nextTask->completed_at = null;
            $nextTask->actual_hours = null;
            $nextTask->parent_task_id = $this->id;
            $nextTask->save();
        }
    }

    private function calculateNextDueDate()
    {
        if (!$this->due_date) {
            return null;
        }

        switch ($this->recurring_pattern) {
            case 'daily':
                return $this->due_date->addDay();
            case 'weekly':
                return $this->due_date->addWeek();
            case 'biweekly':
                return $this->due_date->addWeeks(2);
            case 'monthly':
                return $this->due_date->addMonth();
            case 'seasonal':
                return $this->due_date->addMonths(3);
            default:
                return null;
        }
    }

    public function canBeCompletedInWeather($weatherCondition)
    {
        if (!$this->weather_dependent) {
            return true;
        }

        // Define weather-sensitive tasks
        $weatherSensitiveTasks = [
            'spraying' => ['sunny', 'partly_cloudy'],
            'harvesting' => ['sunny', 'partly_cloudy', 'cloudy'],
            'planting' => ['sunny', 'partly_cloudy', 'light_rain'],
            'irrigation' => ['sunny', 'partly_cloudy', 'cloudy', 'hot'],
        ];

        $allowedWeather = $weatherSensitiveTasks[$this->category] ?? ['sunny', 'partly_cloudy', 'cloudy'];
        
        return in_array($weatherCondition, $allowedWeather);
    }

    // Static utility methods
    public static function getCategoryOptions()
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::CATEGORIES), self::CATEGORIES);
    }

    public static function getPriorityOptions()
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::PRIORITIES), self::PRIORITIES);
    }

    public static function getStatusOptions()
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::STATUSES), self::STATUSES);
    }

    public static function getTaskSummaryForFarm($farmId)
    {
        $tasks = self::where('farm_id', $farmId)->get();
        
        return [
            'total_tasks' => $tasks->count(),
            'completed_tasks' => $tasks->where('status', 'completed')->count(),
            'in_progress_tasks' => $tasks->where('status', 'in_progress')->count(),
            'overdue_tasks' => $tasks->filter(function($task) {
                return $task->is_overdue;
            })->count(),
            'due_today' => $tasks->filter(function($task) {
                return $task->due_date && $task->due_date->isToday();
            })->count(),
            'high_priority_pending' => $tasks->filter(function($task) {
                return in_array($task->priority, ['high', 'urgent', 'critical']) && 
                       $task->status !== 'completed';
            })->count(),
            'completion_rate' => $tasks->count() > 0 ? 
                ($tasks->where('status', 'completed')->count() / $tasks->count()) * 100 : 0,
        ];
    }

    public static function getTasksByCategory($farmId, $startDate = null, $endDate = null)
    {
        $query = self::where('farm_id', $farmId);
        
        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        return $query->selectRaw('category, COUNT(*) as total, 
                                 SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed,
                                 AVG(completion_percentage) as avg_completion')
                    ->groupBy('category')
                    ->get()
                    ->mapWithKeys(function($item) {
                        return [$item->category => [
                            'total' => $item->total,
                            'completed' => $item->completed,
                            'completion_rate' => $item->avg_completion,
                            'name' => self::CATEGORIES[$item->category] ?? $item->category
                        ]];
                    });
    }
}