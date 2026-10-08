<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Traits\HasUuids;

class Bed extends Model
{
    use HasUuids;

    protected $fillable = [
        'farm_id', 'name', 'description', 'bed_type', 'size_length', 'size_width', 
        'size_area', 'area_unit', 'layout_order', 'layout_row', 'layout_column',
        'gps_latitude', 'gps_longitude', 'status', 'health_score', 'soil_type',
        'soil_ph', 'drainage_quality', 'sun_exposure', 'has_irrigation', 'irrigation_type',
        'health_score_updated_at', 'total_yield_kg', 'total_labour_cost',
        'total_input_cost', 'total_revenue', 'average_yield_per_cycle',
        'total_crop_cycles', 'last_planted_date', 'last_harvest_date',
        'current_crop_cycle_id', 'days_since_last_harvest',
        'yield_performance_score', 'cost_efficiency_score', 'reliability_score',
        'total_observations', 'critical_observations', 'last_observation_date',
        'common_issues',
        'needs_maintenance', 'maintenance_notes', 'is_active', 'archive_reason',
        'archived_at', 'created_by', 'updated_by'
    ];

    protected $casts = [
        'size_length' => 'decimal:2',
        'size_width' => 'decimal:2',
        'size_area' => 'decimal:2',
        'gps_latitude' => 'decimal:8',
        'gps_longitude' => 'decimal:8',
        'health_score' => 'decimal:2',
        'soil_ph' => 'decimal:2',
        'has_irrigation' => 'boolean',
        'needs_maintenance' => 'boolean',
        'is_active' => 'boolean',
        'health_score_updated_at' => 'datetime',
        'last_planted_date' => 'datetime',
        'last_harvest_date' => 'datetime',
        'archived_at' => 'datetime',
        'total_yield_kg' => 'decimal:2',
        'total_labour_cost' => 'decimal:2',
        'total_input_cost' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'average_yield_per_cycle' => 'decimal:2',
        'yield_performance_score' => 'decimal:2',
        'cost_efficiency_score' => 'decimal:2',
        'reliability_score' => 'decimal:2',
        'common_issues' => 'array'
    ];

    // Bed types configuration
    public const BED_TYPES = [
        'raised_bed' => 'Raised Bed',
        'flat_bed' => 'Flat Bed', 
        'row' => 'Row Planting',
        'block' => 'Block Planting',
        'greenhouse' => 'Greenhouse',
        'nursery' => 'Nursery Bed'
    ];

    // Status configuration
    public const STATUSES = [
        'empty' => 'Empty',
        'planted' => 'Planted',
        'under_maintenance' => 'Under Maintenance',
        'issues_detected' => 'Issues Detected',
        'ready_to_harvest' => 'Ready to Harvest',
        'harvesting' => 'Harvesting',
        'completed' => 'Season Completed',
        'fallow' => 'Fallow'
    ];

    // Relationships
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(BedNote::class);
    }

    public function cropAssignments(): HasMany
    {
        return $this->hasMany(BedCropAssignment::class);
    }

    public function currentCropAssignment(): HasOne
    {
        return $this->hasOne(BedCropAssignment::class)->where('is_active', true)->latest();
    }

    public function analytics(): HasMany
    {
        return $this->hasMany(BedAnalytics::class);
    }

    public function latestAnalytics(): HasOne
    {
        return $this->hasOne(BedAnalytics::class)->latest('analysis_date');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('bed_type', $type);
    }

    public function scopeHealthy($query, $minScore = 70)
    {
        return $query->where('health_score', '>=', $minScore);
    }

    public function scopeNeedingMaintenance($query)
    {
        return $query->where('needs_maintenance', true);
    }

    public function scopeAvailable($query)
    {
        return $query->active()->where('status', 'empty');
    }

    public function scopeByLayout($query)
    {
        return $query->orderBy('layout_order');
    }

    // Mutators
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($bed) {
            if (!$bed->layout_order) {
                $bed->layout_order = static::where('farm_id', $bed->farm_id)->max('layout_order') + 1;
            }
            
            // Calculate area if length and width provided
            if ($bed->size_length && $bed->size_width) {
                $bed->size_area = $bed->size_length * $bed->size_width;
            }
        });

        static::updating(function ($bed) {
            // Recalculate area if dimensions change
            if ($bed->isDirty(['size_length', 'size_width']) && $bed->size_length && $bed->size_width) {
                $bed->size_area = $bed->size_length * $bed->size_width;
            }
        });
    }

    // Business Logic Methods

    /**
     * Check if bed is available for new crop assignment
     */
    public function isAvailable(): bool
    {
        return $this->is_active && 
               $this->status === 'empty' && 
               !$this->needs_maintenance &&
               !$this->currentCropAssignment;
    }

    /**
     * Assign crop to bed
     */
    public function assignCrop($cropCycleId, array $assignmentData = []): BedCropAssignment
    {
        if (!$this->isAvailable()) {
            throw new \Exception("Bed {$this->name} is not available for crop assignment");
        }

        $cropCycle = CropCycle::where('farm_id', $this->farm_id)->findOrFail($cropCycleId);

        $assignment = $this->cropAssignments()->create(array_merge([
            'crop_cycle_id' => $cropCycleId,
            'farm_id' => $this->farm_id,
            'start_date' => $cropCycle->start_date?->toDateString() ?? now()->toDateString(),
            'expected_end_date' => $cropCycle->expected_harvest_date?->toDateString(),
            'crop_name' => $cropCycle->crop_name,
            'variety' => $cropCycle->variety,
            'expected_yield_kg' => $cropCycle->expected_yield_kg ?? $cropCycle->expected_yield ?? 0,
            'assignment_status' => 'planted',
            'assigned_by' => auth()->id(),
        ], $assignmentData));

        // Update bed status
        $this->update([
            'status' => 'planted',
            'current_crop_cycle_id' => $cropCycleId,
            'last_planted_date' => now()
        ]);

        Log::info('Crop assigned to bed', [
            'bed_id' => $this->id,
            'crop_cycle_id' => $cropCycleId,
            'assignment_id' => $assignment->id
        ]);

        return $assignment;
    }

    /**
     * Complete crop cycle for bed
     */
    public function completeCropCycle(array $completionData = []): bool
    {
        return DB::transaction(function () use ($completionData) {
            $assignment = $this->currentCropAssignment()->lockForUpdate()->first();
            if (!$assignment) {
                return false;
            }

            $assignment->complete($completionData);

            $this->update([
                'status' => 'empty',
                'current_crop_cycle_id' => null,
                'last_harvest_date' => now(),
            ]);

            return true;
        });
    }

    /**
     * Calculate and update health score based on recent observations and performance
     */
    public function calculateHealthScore(): float
    {
        $baseScore = 100;
        
        // Recent observations impact (last 30 days)
        $recentNotes = $this->notes()
            ->where('note_date', '>=', now()->subDays(30))
            ->get();

        $observationImpact = 0;
        foreach ($recentNotes as $note) {
            $impact = match($note->severity) {
                'critical' => -25,
                'high' => -15,
                'medium' => -8,
                'low' => -3
            };
            
            // Weight by note type
            $typeWeight = match($note->note_type) {
                'pest_observation', 'disease_observation' => 1.5,
                'soil_issue', 'drainage_problem' => 1.2,
                'nutrient_deficiency', 'water_stress' => 1.1,
                default => 1.0
            };
            
            $observationImpact += $impact * $typeWeight;
        }

        // Recent performance impact
        $recentAssignments = $this->cropAssignments()
            ->where('actual_end_date', '>=', now()->subDays(90))
            ->get();

        $performanceBonus = 0;
        if ($recentAssignments->isNotEmpty()) {
            $avgYieldEfficiency = $recentAssignments->avg('yield_efficiency_score');
            
            if ($avgYieldEfficiency > 80) {
                $performanceBonus = 10;
            } elseif ($avgYieldEfficiency > 60) {
                $performanceBonus = 5;
            } elseif ($avgYieldEfficiency < 40) {
                $performanceBonus = -10;
            }
        }

        // Maintenance needs impact
        $maintenanceImpact = $this->needs_maintenance ? -15 : 0;

        // Environmental factors
        $environmentalBonus = 0;
        if ($this->drainage_quality === 'excellent') $environmentalBonus += 5;
        if ($this->has_irrigation) $environmentalBonus += 5;
        if ($this->soil_ph >= 6.0 && $this->soil_ph <= 7.5) $environmentalBonus += 5;

        $newScore = $baseScore + $observationImpact + $performanceBonus + $maintenanceImpact + $environmentalBonus;
        $newScore = max(0, min(100, $newScore)); // Ensure 0-100 range

        $this->update([
            'health_score' => $newScore,
            'health_score_updated_at' => now()
        ]);

        return $newScore;
    }

    /**
     * Update productivity metrics from related data
     */
    public function updateProductivityMetrics(): void
    {
        $assignments = $this->cropAssignments()->where('is_active', false)->get();
        
        if ($assignments->isEmpty()) {
            return;
        }

        $totalYield = $assignments->sum('actual_yield_kg');
        $totalLabourCost = $assignments->sum('total_labour_cost');
        $totalInputCost = $assignments->sum('total_input_cost');
        $totalRevenue = $assignments->sum('total_revenue');
        $completedCycles = $assignments->count();
        $avgYieldPerCycle = $completedCycles > 0 ? $totalYield / $completedCycles : 0;

        // Calculate performance scores
        $farmAvgYield = $this->farm->beds()
            ->where('id', '!=', $this->id)
            ->avg('average_yield_per_cycle') ?? 1;
        
        $yieldPerformance = $farmAvgYield > 0 ? ($avgYieldPerCycle / $farmAvgYield) * 100 : 100;

        $totalCost = $totalLabourCost + $totalInputCost;
        $costPerKg = $totalYield > 0 ? $totalCost / $totalYield : 0;
        
        $farmAvgCostPerKg = $this->farm->beds()
            ->where('id', '!=', $this->id)
            ->where('total_yield_kg', '>', 0)
            ->selectRaw('AVG((total_labour_cost + total_input_cost) / total_yield_kg) as avg_cost')
            ->value('avg_cost') ?? 1;
        
        $costEfficiency = $farmAvgCostPerKg > 0 ? (1 - ($costPerKg / $farmAvgCostPerKg)) * 100 + 100 : 100;

        // Calculate reliability score based on consistency
        $yieldVariance = $assignments->count() > 1 ? 
            $this->calculateVariance($assignments->pluck('yield_efficiency_score')) : 0;
        $reliabilityScore = max(0, 100 - ($yieldVariance * 2));

        $this->update([
            'total_yield_kg' => $totalYield,
            'total_labour_cost' => $totalLabourCost,
            'total_input_cost' => $totalInputCost,
            'total_revenue' => $totalRevenue,
            'average_yield_per_cycle' => $avgYieldPerCycle,
            'total_crop_cycles' => $completedCycles,
            'yield_performance_score' => min(150, max(0, $yieldPerformance)),
            'cost_efficiency_score' => min(150, max(0, $costEfficiency)),
            'reliability_score' => $reliabilityScore,
            'days_since_last_harvest' => $this->last_harvest_date ? 
                now()->diffInDays($this->last_harvest_date) : null
        ]);

        // Update observation summary
        $this->updateObservationSummary();
    }

    /**
     * Update observation summary cache
     */
    public function updateObservationSummary(): void
    {
        $totalObs = $this->notes()->count();
        $criticalObs = $this->notes()->where('severity', 'critical')->count();
        $lastObsDate = $this->notes()->latest('note_date')->value('note_date');
        
        // Common issues analysis
        $issueTypes = $this->notes()
            ->where('note_date', '>=', now()->subYear())
            ->groupBy('note_type')
            ->selectRaw('note_type, COUNT(*) as count')
            ->orderBy('count', 'desc')
            ->limit(5)
            ->pluck('count', 'note_type')
            ->toArray();

        $this->update([
            'total_observations' => $totalObs,
            'critical_observations' => $criticalObs,
            'last_observation_date' => $lastObsDate,
            'common_issues' => $issueTypes
        ]);
    }

    /**
     * Get bed utilization rate
     */
    public function getUtilizationRate(int $days = 365): float
    {
        $assignments = $this->cropAssignments()
            ->where('start_date', '>=', now()->subDays($days))
            ->get();
        
        $productiveDays = 0;
        foreach ($assignments as $assignment) {
            $start = Carbon::parse($assignment->start_date);
            $end = $assignment->actual_end_date ? 
                Carbon::parse($assignment->actual_end_date) : now();
            $productiveDays += $start->diffInDays($end);
        }

        return min(100, ($productiveDays / $days) * 100);
    }

    /**
     * Get recommendations for bed improvement
     */
    public function getRecommendations(): array
    {
        $recommendations = [];
        
        if ($this->health_score < 70) {
            $recommendations[] = [
                'type' => 'health_improvement',
                'priority' => 'high',
                'title' => 'Improve Bed Health',
                'description' => 'Health score is below optimal. Review recent observations and address identified issues.',
                'actions' => $this->getHealthImprovementActions()
            ];
        }

        if ($this->yield_performance_score < 60) {
            $recommendations[] = [
                'type' => 'yield_improvement',
                'priority' => 'medium',
                'title' => 'Boost Yield Performance',
                'description' => 'Yield is below farm average. Consider soil testing and nutrient management.',
                'actions' => ['Soil test', 'Nutrient analysis', 'Crop variety review']
            ];
        }

        if ($this->cost_efficiency_score < 60) {
            $recommendations[] = [
                'type' => 'cost_optimization',
                'priority' => 'medium', 
                'title' => 'Optimize Costs',
                'description' => 'Costs are above average. Review input usage and labour efficiency.',
                'actions' => ['Input usage audit', 'Labour time analysis', 'Alternative input sources']
            ];
        }

        if ($this->needs_maintenance) {
            $recommendations[] = [
                'type' => 'maintenance',
                'priority' => 'high',
                'title' => 'Schedule Maintenance',
                'description' => 'Bed requires maintenance attention.',
                'actions' => ['Schedule maintenance', 'Review maintenance notes', 'Resource planning']
            ];
        }

        return $recommendations;
    }

    /**
     * Archive bed with reason
     */
    public function archive(string $reason): bool
    {
        if ($this->currentCropAssignment) {
            throw new \Exception('Cannot archive bed with active crop assignment');
        }

        return $this->update([
            'is_active' => false,
            'status' => 'completed',
            'archive_reason' => $reason,
            'archived_at' => now()
        ]);
    }

    /**
     * Reactivate archived bed
     */
    public function reactivate(): bool
    {
        return $this->update([
            'is_active' => true,
            'status' => 'empty',
            'archive_reason' => null,
            'archived_at' => null
        ]);
    }

    /**
     * Get bed performance summary
     */
    public function getPerformanceSummary(): array
    {
        return [
            'overall_score' => round(($this->yield_performance_score + $this->cost_efficiency_score + $this->reliability_score + $this->health_score) / 4, 1),
            'health_score' => $this->health_score,
            'yield_performance' => $this->yield_performance_score,
            'cost_efficiency' => $this->cost_efficiency_score,
            'reliability' => $this->reliability_score,
            'total_cycles' => $this->total_crop_cycles,
            'utilization_rate' => $this->getUtilizationRate(),
            'profitability' => $this->total_revenue - ($this->total_labour_cost + $this->total_input_cost),
            'roi_percentage' => $this->total_labour_cost + $this->total_input_cost > 0 ? 
                (($this->total_revenue - ($this->total_labour_cost + $this->total_input_cost)) / 
                 ($this->total_labour_cost + $this->total_input_cost)) * 100 : 0
        ];
    }

    // Helper Methods

    private function calculateVariance(array $values): float
    {
        if (count($values) < 2) return 0;
        
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(function($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $values)) / count($values);
        
        return sqrt($variance);
    }

    private function getHealthImprovementActions(): array
    {
        $actions = [];
        $recentCriticalNotes = $this->notes()
            ->where('severity', 'critical')
            ->where('note_date', '>=', now()->subDays(30))
            ->get();

        foreach ($recentCriticalNotes->groupBy('note_type') as $type => $notes) {
            $actions[] = match($type) {
                'pest_observation' => 'Implement pest control measures',
                'disease_observation' => 'Apply disease treatment',
                'soil_issue' => 'Conduct soil amendment',
                'drainage_problem' => 'Improve drainage system',
                'nutrient_deficiency' => 'Apply targeted fertilization',
                'water_stress' => 'Optimize irrigation schedule',
                default => 'Address identified issue'
            };
        }

        return array_unique($actions);
    }
}
