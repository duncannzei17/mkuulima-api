<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CropCycle extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'farm_id', // Required foreign key
        'crop_name',
        'season_name',
        'variety',
        'start_date',
        'expected_harvest_date',
        'actual_harvest_date',
        'season_status',
        'expected_yield_kg',
        'actual_yield_kg',
        'expected_yield',
        'actual_yield',
        'yield_unit',
        'bed_count',
        'land_area',
        'land_area_unit',
        'area_unit',
        'has_nursery',
        'nursery_start_date',
        'expected_transplant_date',
        'actual_transplant_date',
        'seedlings_transplanted',
        'seed_quantity',
        'seed_unit',
        'notes',
        'general_notes',
        'planting_notes',
        'bed_ids',
        'planned_inputs',
        'planned_tasks',
        'growth_progress',
        'health_rating',
        'health_status',
        'task_completion',
        'estimated_cost',
        'actual_cost',
        'estimated_revenue',
        'actual_revenue',
        'irrigation_required',
        'weather_conditions',
        'completed_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'expected_harvest_date' => 'date',
        'actual_harvest_date' => 'date',
        'nursery_start_date' => 'date',
        'expected_transplant_date' => 'date',
        'actual_transplant_date' => 'date',
        'bed_ids' => 'array',
        'planned_inputs' => 'array',
        'planned_tasks' => 'array',
        'weather_conditions' => 'array',
        'land_area' => 'decimal:2',
        'expected_yield_kg' => 'decimal:2',
        'actual_yield_kg' => 'decimal:2',
        'expected_yield' => 'decimal:2',
        'actual_yield' => 'decimal:2',
        'estimated_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'estimated_revenue' => 'decimal:2',
        'actual_revenue' => 'decimal:2',
        'has_nursery' => 'boolean',
        'irrigation_required' => 'boolean',
        'growth_progress' => 'decimal:2',
        'completed_at' => 'datetime',
    ];

    // Database columns now have correct names, no aliases needed

    // Relationships
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CropTask::class);
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(CropProgressLog::class, 'crop_cycle_id');
    }

    public function inputs(): HasMany
    {
        return $this->hasMany(CropInput::class);
    }

    public function fieldObservations(): HasMany
    {
        return $this->hasMany(FieldObservation::class);
    }

    public function harvests(): HasMany
    {
        return $this->hasMany(Harvest::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function labourEntries(): HasMany
    {
        return $this->hasMany(LabourEntry::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereNotIn('season_status', ['completed', 'archived']);
    }

    public function scopeCompleted($query)
    {
        return $query->where('season_status', 'completed');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('season_status', $status);
    }

    public function scopeByHealthStatus($query, $healthStatus)
    {
        return $query->where('health_status', $healthStatus);
    }

    public function scopeHarvestDue($query, $days = 7)
    {
        return $query->where('expected_harvest_date', '<=', now()->addDays($days))
                    ->where('season_status', '!=', 'completed');
    }

    public function scopeByCrop($query, $cropName)
    {
        return $query->where('crop_name', 'like', "%{$cropName}%");
    }

    // Accessors
    public function getDaysToHarvestAttribute()
    {
        if (!$this->expected_harvest_date) return null;
        return now()->diffInDays($this->expected_harvest_date, false);
    }

    public function getDaysFromPlantingAttribute()
    {
        if (!$this->start_date) {
            return 0;
        }
        return now()->diffInDays($this->start_date);
    }

    public function getGrowthProgressAttribute()
    {
        if (!$this->start_date || !$this->expected_harvest_date) {
            return 0;
        }
        
        $totalDays = $this->start_date->diffInDays($this->expected_harvest_date);
        $daysPassed = $this->start_date->diffInDays(now());
        
        return $totalDays > 0 ? min(100, round(($daysPassed / $totalDays) * 100)) : 0;
    }

    public function getIsOverdueAttribute()
    {
        if (!$this->expected_harvest_date) {
            return false;
        }
        return $this->expected_harvest_date < now() && $this->season_status !== 'completed';
    }

    public function getCurrentStageAttribute()
    {
        // TODO: Implement when progress logs table exists
        return $this->season_status;
    }

    public function getLatestHealthRatingAttribute()
    {
        // TODO: Implement when progress logs table exists  
        return $this->health_rating ?? 5; // Default health rating
    }

    public function getProfitMarginAttribute()
    {
        if ($this->actual_revenue > 0 && $this->actual_cost > 0) {
            return round((($this->actual_revenue - $this->actual_cost) / $this->actual_revenue) * 100, 2);
        }
        return null;
    }

    // Business Logic Methods
    public function updateStatus($newStatus)
    {
        $validTransitions = $this->getValidStatusTransitions();
        
        if (!in_array($newStatus, $validTransitions[$this->season_status] ?? [])) {
            throw new \InvalidArgumentException("Invalid status transition from {$this->season_status} to {$newStatus}");
        }

        $this->season_status = $newStatus;
        
        if ($newStatus === 'completed') {
            $this->completed_at = now();
        }
        
        $this->save();
    }

    protected function getValidStatusTransitions()
    {
        return [
            'planned' => ['nursery', 'transplanted', 'growing'],
            'nursery' => ['transplanted', 'growing'],
            'transplanted' => ['growing'],
            'growing' => ['harvest', 'completed'],
            'harvest' => ['completed'],
            'completed' => ['archived'],
        ];
    }

    public function canBeEdited()
    {
        return !in_array($this->season_status, ['completed', 'archived']);
    }

    public function getTotalInputCost()
    {
        // Check if crop_inputs table exists before querying
        try {
            return $this->inputs()->sum('actual_total_cost') ?: $this->inputs()->sum('planned_total_cost');
        } catch (\Exception $e) {
            // Return 0 if table doesn't exist or relationship fails
            return 0;
        }
    }

    public function getCompletedTasksCount()
    {
        return $this->tasks()->where('status', 'completed')->count();
    }

    public function getPendingTasksCount()
    {
        return $this->tasks()->where('status', 'scheduled')->count();
    }

    public function getOverdueTasksCount()
    {
        return $this->tasks()
                    ->where('status', 'scheduled')
                    ->where('scheduled_date', '<', now())
                    ->count();
    }

    public function addProgressLog(array $data)
    {
        $data['crop_cycle_id'] = $this->id;
        return $this->progressLogs()->create($data);
    }

    public function scheduleTask(array $taskData)
    {
        $taskData['crop_cycle_id'] = $this->id;
        return $this->tasks()->create($taskData);
    }

    public function addInput(array $inputData)
    {
        $inputData['crop_cycle_id'] = $this->id;
        
        // Auto-calculate safe harvest date for chemicals
        if (in_array($inputData['input_type'], ['pesticide', 'herbicide', 'fungicide'])) {
            $phi = $inputData['pre_harvest_interval_days'] ?? 0;
            $applicationDate = $inputData['planned_application_date'] ?? $inputData['actual_application_date'];
            
            if ($applicationDate && $phi > 0) {
                $inputData['safe_harvest_date'] = Carbon::parse($applicationDate)->addDays($phi);
            }
        }
        
        // Check if crop_inputs table exists before creating
        try {
            return $this->inputs()->create($inputData);
        } catch (\Exception $e) {
            // Return null if table doesn't exist
            return null;
        }
    }

    public function getHarvestForecast()
    {
        $growthProgress = $this->growth_progress;
        $healthFactor = $this->getHealthFactor();
        
        $forecastYield = $this->expected_yield * ($healthFactor / 100);
        $forecastDate = $this->expected_harvest_date;
        
        // Adjust based on recent progress
        if ($growthProgress > 100) {
            $forecastDate = $forecastDate->subDays(($growthProgress - 100) * 0.5);
        }
        
        return [
            'expected_date' => $forecastDate,
            'forecast_yield' => round($forecastYield, 2),
            'confidence' => $this->progressLogs()->count() > 5 ? 'high' : 'medium',
            'health_factor' => $healthFactor,
        ];
    }

    protected function getHealthFactor()
    {
        $healthMap = [
            'excellent' => 100,
            'good' => 85,
            'fair' => 70,
            'poor' => 50,
            'critical' => 25,
        ];
        
        return $healthMap[$this->latest_health_rating] ?? 85;
    }

    public function archive($reason = null)
    {
        $this->season_status = 'archived';
        $archiveNote = "Archived on " . now()->format('Y-m-d H:i:s') . ($reason ? ": {$reason}" : "");
        $this->general_notes = ($this->general_notes ? $this->general_notes . "\n\n" : '') . $archiveNote;
        $this->notes = ($this->notes ? $this->notes . "\n\n" : '') . $archiveNote;
        $this->save();
    }

    public function recalculateSeasonHealthScore()
    {
        $observations = $this->fieldObservations()
            ->where('status', 'approved')
            ->get();

        if ($observations->isEmpty()) {
            if ($this->health_status !== 'excellent') {
                $this->health_status = 'excellent';
                $this->save();
            }
            $this->updateProfitabilitySnapshot();
            return 100;
        }

        $totalImpact = $observations->sum('season_health_impact');
        $baseScore = 100;
        
        // Calculate score based on cumulative impact
        $healthScore = max(0, $baseScore + $totalImpact);

        // Update the health status based on the score
        $newHealthStatus = $this->getHealthStatusFromScore($healthScore);
        
        if ($this->health_status !== $newHealthStatus) {
            $this->health_status = $newHealthStatus;
            $this->save();
        }

        // Update profitability snapshot if exists
        $this->updateProfitabilitySnapshot();

        return $healthScore;
    }

    protected function getHealthStatusFromScore(float $score): string
    {
        return match(true) {
            $score >= 90 => 'excellent',
            $score >= 75 => 'good',
            $score >= 60 => 'fair',
            $score >= 40 => 'poor',
            default => 'critical'
        };
    }

    protected function updateProfitabilitySnapshot()
    {
        // Find the latest profitability snapshot for this crop cycle
        $snapshot = \App\Models\ProfitabilitySnapshot::where('crop_cycle_id', $this->id)
            ->latest('calculated_at')
            ->first();

        if ($snapshot) {
            $healthScore = $this->recalculateHealthScoreOnly();
            $previousScore = $snapshot->season_health_score;
            
            $snapshot->season_health_score = $healthScore;
            $snapshot->save();

            // Fire event for season health score update
            event(new \App\Events\SeasonHealthScoreUpdated($snapshot, $previousScore));
        }
    }

    private function recalculateHealthScoreOnly(): float
    {
        $observations = $this->fieldObservations()
            ->where('status', 'approved')
            ->get();

        if ($observations->isEmpty()) {
            return 100;
        }

        $totalImpact = $observations->sum('season_health_impact');
        return max(0, 100 + $totalImpact);
    }

    // Static utility methods
    public static function getCropNames()
    {
        return [
            'Onions', 'Sukuma Wiki', 'Tomatoes', 'Capsicum', 'Coriander',
            'Spinach', 'Carrots', 'Cabbage', 'Lettuce', 'Beans',
            'Maize', 'Potatoes', 'Sweet Potatoes', 'Kales', 'Peas'
        ];
    }

    public static function getStatusLabels()
    {
        return [
            'planned' => 'Planning Phase',
            'nursery' => 'Nursery Stage',
            'transplanted' => 'Transplanted',
            'growing' => 'Growing',
            'harvest' => 'Harvest Ready',
            'completed' => 'Completed',
            'archived' => 'Archived',
        ];
    }

    public static function getHealthStatusLabels()
    {
        return [
            'excellent' => 'Excellent Health',
            'good' => 'Good Health',
            'fair' => 'Fair Condition',
            'poor' => 'Poor Health',
            'critical' => 'Critical Condition',
        ];
    }
}
