<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LabourSession extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'farm_id',
        'crop_id',
        'worker_id',
        'task_id',
        'supervisor_id',
        'work_date',
        'start_time',
        'end_time',
        'hours_worked',
        'break_hours',
        'hourly_rate',
        'total_payment',
        'task_category',
        'task_description',
        'area_covered',
        'area_unit',
        'productivity_rate',
        'quality_rating',
        'attendance_status',
        'payment_status',
        'payment_method',
        'weather_conditions',
        'equipment_used',
        'notes',
        'photos',
        'gps_location',
        'approved_by',
        'approved_at',
        'metadata'
    ];

    protected $casts = [
        'work_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'approved_at' => 'datetime',
        'hours_worked' => 'decimal:2',
        'break_hours' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'total_payment' => 'decimal:2',
        'area_covered' => 'decimal:2',
        'productivity_rate' => 'decimal:2',
        'quality_rating' => 'decimal:1',
        'photos' => 'array',
        'gps_location' => 'array',
        'equipment_used' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'attendance_status' => 'present',
        'payment_status' => 'pending',
        'break_hours' => 0,
        'area_unit' => 'hectares',
    ];

    // Task categories
    public const TASK_CATEGORIES = [
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
        'other' => 'Other Tasks'
    ];

    // Attendance statuses
    public const ATTENDANCE_STATUSES = [
        'present' => 'Present',
        'late' => 'Late',
        'half_day' => 'Half Day',
        'absent' => 'Absent',
        'sick_leave' => 'Sick Leave',
        'permission' => 'Permission/Leave'
    ];

    // Weather conditions
    public const WEATHER_CONDITIONS = [
        'sunny' => 'Sunny',
        'partly_cloudy' => 'Partly Cloudy',
        'cloudy' => 'Cloudy',
        'light_rain' => 'Light Rain',
        'heavy_rain' => 'Heavy Rain',
        'windy' => 'Windy',
        'hot' => 'Very Hot',
        'cold' => 'Cold',
        'humid' => 'Humid'
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

    public function worker()
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopeByFarm($query, $farmId)
    {
        return $query->where('farm_id', $farmId);
    }

    public function scopeByWorker($query, $workerId)
    {
        return $query->where('worker_id', $workerId);
    }

    public function scopeByTask($query, $taskCategory)
    {
        return $query->where('task_category', $taskCategory);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('work_date', [$startDate, $endDate]);
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('work_date', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('work_date', now()->month)
                    ->whereYear('work_date', now()->year);
    }

    public function scopePending($query)
    {
        return $query->where('payment_status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    public function scopeApproved($query)
    {
        return $query->whereNotNull('approved_at');
    }

    public function scopeUnapproved($query)
    {
        return $query->whereNull('approved_at');
    }

    // Accessors & Mutators
    public function getTaskCategoryNameAttribute()
    {
        return self::TASK_CATEGORIES[$this->task_category] ?? $this->task_category;
    }

    public function getAttendanceStatusNameAttribute()
    {
        return self::ATTENDANCE_STATUSES[$this->attendance_status] ?? $this->attendance_status;
    }

    public function getWeatherConditionNameAttribute()
    {
        return self::WEATHER_CONDITIONS[$this->weather_conditions] ?? $this->weather_conditions;
    }

    public function getFormattedPaymentAttribute()
    {
        return 'KES ' . number_format($this->total_payment, 2);
    }

    public function getProductivityPerHourAttribute()
    {
        if ($this->hours_worked > 0 && $this->area_covered > 0) {
            return $this->area_covered / $this->hours_worked;
        }
        return 0;
    }

    public function getEfficiencyRatingAttribute()
    {
        // Calculate efficiency based on quality rating and productivity
        $qualityWeight = 0.6;
        $productivityWeight = 0.4;
        
        $qualityScore = ($this->quality_rating / 5) * 100; // Convert to percentage
        
        // Productivity score (simplified calculation)
        $avgProductivity = 0.5; // Average hectares per hour (example)
        $productivityScore = min(($this->productivity_per_hour / $avgProductivity) * 100, 100);
        
        return ($qualityScore * $qualityWeight) + ($productivityScore * $productivityWeight);
    }

    // Mutators for automatic calculations
    public function setStartTimeAttribute($value)
    {
        $this->attributes['start_time'] = $value;
        $this->calculateHoursWorked();
    }

    public function setEndTimeAttribute($value)
    {
        $this->attributes['end_time'] = $value;
        $this->calculateHoursWorked();
    }

    public function setBreakHoursAttribute($value)
    {
        $this->attributes['break_hours'] = $value;
        $this->calculateHoursWorked();
    }

    public function setHoursWorkedAttribute($value)
    {
        $this->attributes['hours_worked'] = $value;
        $this->calculateTotalPayment();
    }

    public function setHourlyRateAttribute($value)
    {
        $this->attributes['hourly_rate'] = $value;
        $this->calculateTotalPayment();
    }

    // Business Logic Methods
    private function calculateHoursWorked()
    {
        if (isset($this->attributes['start_time']) && isset($this->attributes['end_time'])) {
            $startTime = \Carbon\Carbon::parse($this->attributes['start_time']);
            $endTime = \Carbon\Carbon::parse($this->attributes['end_time']);
            $breakHours = $this->attributes['break_hours'] ?? 0;
            
            $totalMinutes = $endTime->diffInMinutes($startTime);
            $workingHours = ($totalMinutes / 60) - $breakHours;
            
            $this->attributes['hours_worked'] = max(0, round($workingHours, 2));
            $this->calculateTotalPayment();
        }
    }

    private function calculateTotalPayment()
    {
        if (isset($this->attributes['hours_worked']) && isset($this->attributes['hourly_rate'])) {
            $this->attributes['total_payment'] = $this->attributes['hours_worked'] * $this->attributes['hourly_rate'];
        }
    }

    public function calculateProductivityRate()
    {
        if ($this->hours_worked > 0 && $this->area_covered > 0) {
            $this->productivity_rate = $this->area_covered / $this->hours_worked;
            $this->save();
        }
    }

    public function approve($supervisorId, $qualityRating = null)
    {
        $this->approved_by = $supervisorId;
        $this->approved_at = now();
        
        if ($qualityRating) {
            $this->quality_rating = $qualityRating;
        }
        
        $this->metadata = array_merge($this->metadata ?? [], [
            'approval_notes' => 'Approved by supervisor',
        ]);
        
        $this->save();
    }

    public function markAsPaid($paymentMethod = 'mpesa')
    {
        $this->payment_status = 'paid';
        $this->payment_method = $paymentMethod;
        $this->metadata = array_merge($this->metadata ?? [], [
            'paid_at' => now()->toISOString(),
        ]);
        $this->save();
    }

    public function isOvertime()
    {
        return $this->hours_worked > 8; // Standard 8-hour workday
    }

    public function getOvertimeHours()
    {
        return max(0, $this->hours_worked - 8);
    }

    public function getOvertimePay($overtimeRate = 1.5)
    {
        $overtimeHours = $this->getOvertimeHours();
        return $overtimeHours * $this->hourly_rate * $overtimeRate;
    }

    // Static utility methods
    public static function getTaskCategoryOptions()
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::TASK_CATEGORIES), self::TASK_CATEGORIES);
    }

    public static function getAttendanceStatusOptions()
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::ATTENDANCE_STATUSES), self::ATTENDANCE_STATUSES);
    }

    public static function getWeatherConditionOptions()
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::WEATHER_CONDITIONS), self::WEATHER_CONDITIONS);
    }

    public static function getWorkerProductivityReport($workerId, $startDate, $endDate)
    {
        $sessions = self::where('worker_id', $workerId)
                       ->whereBetween('work_date', [$startDate, $endDate])
                       ->get();

        return [
            'total_hours' => $sessions->sum('hours_worked'),
            'total_payment' => $sessions->sum('total_payment'),
            'average_rating' => $sessions->avg('quality_rating'),
            'total_area_covered' => $sessions->sum('area_covered'),
            'average_productivity' => $sessions->avg('productivity_rate'),
            'attendance_rate' => ($sessions->where('attendance_status', 'present')->count() / max($sessions->count(), 1)) * 100,
            'task_breakdown' => $sessions->groupBy('task_category')
                                       ->map(function($group) {
                                           return [
                                               'hours' => $group->sum('hours_worked'),
                                               'sessions' => $group->count()
                                           ];
                                       }),
        ];
    }

    public static function getFarmLabourAnalytics($farmId, $startDate, $endDate)
    {
        $sessions = self::where('farm_id', $farmId)
                       ->whereBetween('work_date', [$startDate, $endDate])
                       ->with('worker')
                       ->get();

        return [
            'total_labour_cost' => $sessions->sum('total_payment'),
            'total_hours' => $sessions->sum('hours_worked'),
            'total_sessions' => $sessions->count(),
            'average_hourly_rate' => $sessions->avg('hourly_rate'),
            'unique_workers' => $sessions->pluck('worker_id')->unique()->count(),
            'task_distribution' => $sessions->groupBy('task_category')
                                           ->map(function($group, $task) {
                                               return [
                                                   'task_name' => self::TASK_CATEGORIES[$task] ?? $task,
                                                   'hours' => $group->sum('hours_worked'),
                                                   'cost' => $group->sum('total_payment'),
                                                   'sessions' => $group->count()
                                               ];
                                           }),
            'daily_breakdown' => $sessions->groupBy(function($session) {
                                             return $session->work_date->format('Y-m-d');
                                         })
                                         ->map(function($group) {
                                             return [
                                                 'hours' => $group->sum('hours_worked'),
                                                 'cost' => $group->sum('total_payment'),
                                                 'workers' => $group->pluck('worker_id')->unique()->count()
                                             ];
                                         }),
        ];
    }
}