<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use App\Traits\HasUuids;

class BedCropAssignment extends Model
{
    use HasUuids;

    protected $fillable = [
        'bed_id', 'crop_cycle_id', 'farm_id', 'start_date', 'expected_end_date',
        'actual_end_date', 'is_active', 'crop_name', 'variety', 'expected_yield_kg',
        'actual_yield_kg', 'area_used_sqm', 'area_percentage_used', 'plant_count',
        'plant_density_per_sqm', 'yield_per_sqm', 'yield_efficiency_score',
        'performance_rating', 'total_labour_cost', 'total_input_cost',
        'total_harvest_cost', 'total_cost', 'cost_per_kg', 'total_revenue',
        'net_profit', 'profit_margin_percentage', 'roi_percentage',
        'health_score_start', 'health_score_end', 'health_impact',
        'total_observations', 'critical_issues', 'days_to_harvest',
        'expected_days_to_harvest', 'timeline_variance_days', 'timeline_performance',
        'harvest_quality', 'marketable_yield_percentage', 'waste_percentage',
        'season', 'weather_summary', 'irrigation_used', 'water_usage_liters',
        'assignment_status', 'completion_notes', 'assigned_by', 'completed_by',
        'status_updated_at'
    ];

    protected $casts = [
        'start_date' => 'date',
        'expected_end_date' => 'date',
        'actual_end_date' => 'date',
        'is_active' => 'boolean',
        'expected_yield_kg' => 'decimal:2',
        'actual_yield_kg' => 'decimal:2',
        'area_used_sqm' => 'decimal:2',
        'plant_density_per_sqm' => 'decimal:2',
        'yield_per_sqm' => 'decimal:2',
        'yield_efficiency_score' => 'decimal:2',
        'total_labour_cost' => 'decimal:2',
        'total_input_cost' => 'decimal:2',
        'total_harvest_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'cost_per_kg' => 'decimal:2',
        'total_revenue' => 'decimal:2',
        'net_profit' => 'decimal:2',
        'profit_margin_percentage' => 'decimal:2',
        'roi_percentage' => 'decimal:2',
        'health_score_start' => 'decimal:2',
        'health_score_end' => 'decimal:2',
        'health_impact' => 'decimal:2',
        'marketable_yield_percentage' => 'decimal:2',
        'waste_percentage' => 'decimal:2',
        'irrigation_used' => 'boolean',
        'water_usage_liters' => 'decimal:2',
        'weather_summary' => 'array',
        'status_updated_at' => 'datetime'
    ];

    // Assignment statuses
    public const STATUSES = [
        'planned' => 'Planned',
        'planted' => 'Planted',
        'growing' => 'Growing',
        'harvesting' => 'Harvesting',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'abandoned' => 'Abandoned'
    ];

    // Performance ratings
    public const PERFORMANCE_RATINGS = [
        'poor' => 'Poor',
        'below_average' => 'Below Average',
        'average' => 'Average',
        'good' => 'Good',
        'excellent' => 'Excellent'
    ];

    // Timeline performance
    public const TIMELINE_PERFORMANCE = [
        'early' => 'Early',
        'on_time' => 'On Time',
        'delayed' => 'Delayed'
    ];

    // Harvest quality
    public const HARVEST_QUALITIES = [
        'poor' => 'Poor',
        'fair' => 'Fair',
        'good' => 'Good',
        'excellent' => 'Excellent'
    ];

    // Seasons
    public const SEASONS = [
        'dry' => 'Dry Season',
        'wet' => 'Wet Season',
        'transition' => 'Transition Season'
    ];

    // Relationships
    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCompleted($query)
    {
        return $query->where('assignment_status', 'completed');
    }

    public function scopeForBed($query, $bedId)
    {
        return $query->where('bed_id', $bedId);
    }

    public function scopeForCrop($query, $cropName)
    {
        return $query->where('crop_name', $cropName);
    }

    public function scopeInSeason($query, $season)
    {
        return $query->where('season', $season);
    }

    public function scopeByPerformance($query, $rating)
    {
        return $query->where('performance_rating', $rating);
    }

    public function scopeHighPerforming($query)
    {
        return $query->whereIn('performance_rating', ['good', 'excellent']);
    }

    public function scopePoorPerforming($query)
    {
        return $query->whereIn('performance_rating', ['poor', 'below_average']);
    }

    // Mutators and Accessors
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($assignment) {
            // Set initial values
            $assignment->health_score_start = $assignment->bed->health_score ?? 100;
            
            // Auto-detect season if not set
            if (!$assignment->season) {
                $assignment->season = $assignment->detectSeason();
            }
        });

    }

    // Business Logic Methods

    /**
     * Complete the assignment with final calculations
     */
    public function complete(array $completionData = []): bool
    {
        if ($this->assignment_status === 'completed') {
            return true;
        }

        $this->update(array_merge([
            'assignment_status' => 'completed',
            'actual_end_date' => now()->toDateString(),
            'is_active' => false,
            'completed_by' => auth()->id(),
            'status_updated_at' => now()
        ], $completionData));

        // Calculate final metrics
        $this->calculateFinalMetrics();

        // Update bed health and metrics
        $this->bed->calculateHealthScore();
        $this->bed->updateProductivityMetrics();

        Log::info('Bed crop assignment completed', [
            'assignment_id' => $this->id,
            'bed_id' => $this->bed_id,
            'crop_cycle_id' => $this->crop_cycle_id,
            'yield_efficiency' => $this->yield_efficiency_score
        ]);

        return true;
    }

    /**
     * Calculate final assignment metrics
     */
    public function calculateFinalMetrics(): void
    {
        // Calculate yield efficiency
        $this->calculateYieldEfficiency();
        
        // Calculate cost metrics
        $this->calculateCostMetrics();
        
        // Calculate profitability
        $this->calculateProfitability();
        
        // Calculate performance rating
        $this->calculatePerformanceRating();
        
        // Calculate timeline performance
        $this->calculateTimelinePerformance();
        
        // Calculate health impact
        $this->calculateHealthImpact();
        
        // Update observation summary
        $this->updateObservationSummary();

        $this->save();
    }

    /**
     * Calculate yield efficiency score
     */
    public function calculateYieldEfficiency(): float
    {
        if (!$this->expected_yield_kg || $this->expected_yield_kg == 0) {
            $this->yield_efficiency_score = 0;
            return 0;
        }

        $efficiency = ($this->actual_yield_kg / $this->expected_yield_kg) * 100;
        $this->yield_efficiency_score = min(150, max(0, $efficiency)); // Cap at 150%

        // Calculate yield per square meter
        if ($this->area_used_sqm && $this->area_used_sqm > 0) {
            $this->yield_per_sqm = $this->actual_yield_kg / $this->area_used_sqm;
        }

        return $this->yield_efficiency_score;
    }

    /**
     * Calculate cost metrics
     */
    public function calculateCostMetrics(): void
    {
        // Get costs from related modules
        $this->calculateLabourCosts();
        $this->calculateInputCosts();
        $this->calculateHarvestCosts();

        // Calculate totals
        $this->total_cost = $this->total_labour_cost + $this->total_input_cost + $this->total_harvest_cost;
        
        // Calculate cost per kg
        if ($this->actual_yield_kg && $this->actual_yield_kg > 0) {
            $this->cost_per_kg = $this->total_cost / $this->actual_yield_kg;
        }
    }

    /**
     * Calculate profitability metrics
     */
    public function calculateProfitability(): void
    {
        // Get revenue from sales data
        $this->calculateRevenue();
        
        // Calculate profit
        $this->net_profit = $this->total_revenue - $this->total_cost;
        
        // Calculate profit margin
        if ($this->total_revenue && $this->total_revenue > 0) {
            $this->profit_margin_percentage = ($this->net_profit / $this->total_revenue) * 100;
        }
        
        // Calculate ROI
        if ($this->total_cost && $this->total_cost > 0) {
            $this->roi_percentage = ($this->net_profit / $this->total_cost) * 100;
        }
    }

    /**
     * Calculate performance rating based on multiple factors
     */
    public function calculatePerformanceRating(): string
    {
        $score = 0;
        $factors = 0;

        // Yield efficiency factor (40% weight)
        if ($this->yield_efficiency_score !== null) {
            $yieldScore = min(100, $this->yield_efficiency_score);
            $score += $yieldScore * 0.4;
            $factors += 0.4;
        }

        // Cost efficiency factor (25% weight)
        if ($this->cost_per_kg !== null && $this->cost_per_kg > 0) {
            $farmAvgCost = $this->getFarmAverageCostPerKg();
            if ($farmAvgCost > 0) {
                $costEfficiency = max(0, (1 - ($this->cost_per_kg / $farmAvgCost)) * 100 + 100);
                $score += min(100, $costEfficiency) * 0.25;
                $factors += 0.25;
            }
        }

        // Timeline factor (20% weight)
        if ($this->timeline_variance_days !== null) {
            $timelineScore = max(0, 100 - abs($this->timeline_variance_days * 5));
            $score += $timelineScore * 0.2;
            $factors += 0.2;
        }

        // Health impact factor (15% weight)
        if ($this->health_impact !== null) {
            $healthScore = max(0, 100 + ($this->health_impact * 2));
            $score += min(100, $healthScore) * 0.15;
            $factors += 0.15;
        }

        $finalScore = $factors > 0 ? $score / $factors : 50;

        $rating = match(true) {
            $finalScore >= 85 => 'excellent',
            $finalScore >= 70 => 'good',
            $finalScore >= 50 => 'average',
            $finalScore >= 30 => 'below_average',
            default => 'poor'
        };

        $this->performance_rating = $rating;
        return $rating;
    }

    /**
     * Calculate timeline performance
     */
    public function calculateTimelinePerformance(): void
    {
        if (!$this->expected_days_to_harvest || !$this->actual_end_date || !$this->start_date) {
            return;
        }

        $this->days_to_harvest = Carbon::parse($this->start_date)->diffInDays($this->actual_end_date);
        $this->timeline_variance_days = $this->days_to_harvest - $this->expected_days_to_harvest;

        $this->timeline_performance = match(true) {
            $this->timeline_variance_days <= -3 => 'early',
            $this->timeline_variance_days <= 3 => 'on_time',
            default => 'delayed'
        };
    }

    /**
     * Calculate health impact during assignment period
     */
    public function calculateHealthImpact(): void
    {
        $this->health_score_end = $this->bed->health_score ?? 100;
        $this->health_impact = $this->health_score_end - $this->health_score_start;
    }

    /**
     * Update observation summary for this assignment period
     */
    public function updateObservationSummary(): void
    {
        $observations = $this->bed->notes()
            ->where('note_date', '>=', $this->start_date)
            ->where('note_date', '<=', $this->actual_end_date ?? now())
            ->get();

        $this->total_observations = $observations->count();
        $this->critical_issues = $observations->where('severity', 'critical')->count();
    }

    /**
     * Get labour costs for this assignment period
     */
    private function calculateLabourCosts(): void
    {
        $entries = LabourEntry::where('bed_id', $this->bed_id)
            ->where('crop_cycle_id', $this->crop_cycle_id)
            ->where('status', 'approved')
            ->get(['amount', 'payment_type', 'units']);

        $this->total_labour_cost = $entries->sum(function (LabourEntry $entry) {
            return $entry->payment_type === 'piece_rate' && $entry->units
                ? (float) $entry->amount * $entry->units
                : (float) $entry->amount;
        });
    }

    /**
     * Get input costs for this assignment period
     */
    private function calculateInputCosts(): void
    {
        $directExpenseCost = 0;
        if (Schema::hasTable('expenses')) {
            $directExpenseCost = (float) Expense::query()
                ->with('category')
                ->where('bed_id', $this->bed_id)
                ->where('crop_cycle_id', $this->crop_cycle_id)
                ->where('status', 'approved')
                ->where('is_deleted', false)
                ->get()
                ->reject(function (Expense $expense) {
                    $category = strtolower((string) ($expense->category?->slug ?: $expense->category?->name));
                    return str_contains($category, 'labour')
                        || str_contains($category, 'labor')
                        || str_contains($category, 'worker-wage');
                })
                ->sum(fn (Expense $expense) => (float) $expense->total_amount);
        }

        if (!Schema::hasTable('stock_movements')) {
            $this->total_input_cost = $directExpenseCost;
            return;
        }

        $inventoryCost = (float) DB::table('stock_movements as movement')
            ->leftJoin('inventory_items as item', 'item.id', '=', 'movement.inventory_item_id')
            ->where('movement.bed_id', $this->bed_id)
            ->where('movement.crop_cycle_id', $this->crop_cycle_id)
            ->where('movement.movement_type', 'out')
            ->selectRaw('COALESCE(SUM(movement.quantity * COALESCE(movement.cost_per_unit, item.average_cost, item.cost_per_unit, 0)), 0) as total')
            ->value('total');

        $this->total_input_cost = $directExpenseCost + $inventoryCost;
    }

    /**
     * Get harvest costs for this assignment
     */
    private function calculateHarvestCosts(): void
    {
        // Harvest labour is already represented by approved labour entries.
        $this->total_harvest_cost = 0;
    }

    /**
     * Calculate revenue from sales allocations
     */
    private function calculateRevenue(): void
    {
        if (!Schema::hasColumn('harvest_beds', 'bed_id')) {
            $this->total_revenue = 0;
            return;
        }

        $this->total_revenue = (float) DB::table('sales as sale')
            ->join('harvests as harvest', 'harvest.id', '=', 'sale.harvest_id')
            ->join('harvest_beds as harvest_bed', 'harvest_bed.harvest_id', '=', 'harvest.id')
            ->where('harvest_bed.bed_id', $this->bed_id)
            ->where('harvest.crop_cycle_id', $this->crop_cycle_id)
            ->where('sale.status', 'approved')
            ->where('harvest.status', 'approved')
            ->selectRaw('COALESCE(SUM(sale.net_income * harvest_bed.quantity / NULLIF(harvest.total_quantity, 0)), 0) as total')
            ->value('total');
    }

    /**
     * Get farm average cost per kg for comparison
     */
    private function getFarmAverageCostPerKg(): float
    {
        return static::where('farm_id', $this->farm_id)
            ->where('crop_name', $this->crop_name)
            ->where('assignment_status', 'completed')
            ->where('cost_per_kg', '>', 0)
            ->avg('cost_per_kg') ?? 0;
    }

    /**
     * Detect season based on start date
     */
    private function detectSeason(): string
    {
        $month = Carbon::parse($this->start_date)->month;
        
        // Kenya seasonal patterns (adjust based on local conditions)
        return match(true) {
            in_array($month, [12, 1, 2]) => 'dry',
            in_array($month, [3, 4, 5, 10, 11]) => 'wet',
            default => 'transition'
        };
    }

    /**
     * Get assignment duration in days
     */
    public function getDurationDays(): ?int
    {
        if (!$this->actual_end_date) {
            return Carbon::parse($this->start_date)->diffInDays(now());
        }

        return Carbon::parse($this->start_date)->diffInDays($this->actual_end_date);
    }

    /**
     * Check if assignment is overdue
     */
    public function isOverdue(): bool
    {
        return $this->is_active && 
               $this->expected_end_date && 
               now()->isAfter($this->expected_end_date);
    }

    /**
     * Get days overdue or remaining
     */
    public function getDaysOverdue(): ?int
    {
        if (!$this->expected_end_date) {
            return null;
        }

        return now()->diffInDays($this->expected_end_date, false);
    }

    /**
     * Get assignment efficiency summary
     */
    public function getEfficiencySummary(): array
    {
        return [
            'yield_efficiency' => $this->yield_efficiency_score,
            'timeline_performance' => $this->timeline_performance,
            'cost_efficiency' => $this->cost_per_kg,
            'profit_margin' => $this->profit_margin_percentage,
            'roi' => $this->roi_percentage,
            'performance_rating' => $this->performance_rating,
            'health_impact' => $this->health_impact
        ];
    }

    /**
     * Get recommendations for improvement
     */
    public function getRecommendations(): array
    {
        $recommendations = [];

        if ($this->yield_efficiency_score < 70) {
            $recommendations[] = [
                'type' => 'yield_improvement',
                'priority' => 'high',
                'message' => 'Yield was below expected. Consider soil testing and improved crop varieties.',
                'actions' => ['Soil test', 'Variety assessment', 'Nutrition plan']
            ];
        }

        if ($this->timeline_variance_days > 7) {
            $recommendations[] = [
                'type' => 'timeline_optimization',
                'priority' => 'medium',
                'message' => 'Crop cycle took longer than expected. Review growing conditions.',
                'actions' => ['Weather analysis', 'Nutrition timing', 'Pest management review']
            ];
        }

        if ($this->health_impact < -10) {
            $recommendations[] = [
                'type' => 'soil_health',
                'priority' => 'high',
                'message' => 'Bed health declined during this cycle. Implement soil restoration.',
                'actions' => ['Organic matter addition', 'Crop rotation', 'Soil amendment']
            ];
        }

        return $recommendations;
    }
}
