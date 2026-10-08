<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Harvest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'crop_cycle_id',
        'harvest_date',
        'total_quantity',
        'unit',
        'grade_type',
        'simple_grade',
        'detailed_grade',
        'worker_id',
        'notes',
        'photos',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'expected_quantity',
        'variance_percentage',
        'weather_conditions',
        'moisture_content',
        'is_final_harvest'
    ];

    protected $casts = [
        'harvest_date' => 'date',
        'approved_at' => 'datetime',
        'photos' => 'array',
        'weather_conditions' => 'array',
        'total_quantity' => 'decimal:3',
        'expected_quantity' => 'decimal:3',
        'variance_percentage' => 'decimal:2',
        'moisture_content' => 'decimal:2',
        'is_final_harvest' => 'boolean',
    ];

    protected $attributes = [
        'status' => 'approved',
        'grade_type' => 'simple',
        'is_final_harvest' => false,
    ];

    // Relationships
    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function harvestBeds(): HasMany
    {
        return $this->hasMany(HarvestBed::class);
    }

    public function harvestNotes(): HasMany
    {
        return $this->hasMany(HarvestNote::class);
    }

    public function salesAllocations(): HasMany
    {
        return $this->hasMany(HarvestSalesAllocation::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeForCrop($query, string $cropCycleId)
    {
        return $query->where('crop_cycle_id', $cropCycleId);
    }

    public function scopeByWorker($query, string $workerId)
    {
        return $query->where('worker_id', $workerId);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('harvest_date', [$startDate, $endDate]);
    }

    public function scopeByGrade($query, string $grade)
    {
        return $query->where(function ($q) use ($grade) {
            $q->where('simple_grade', $grade)
              ->orWhere('detailed_grade', $grade);
        });
    }

    // Business Logic Methods

    /**
     * Approve harvest entry
     */
    public function approve(string $userId): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        // Calculate variance if expected quantity exists
        if ($this->expected_quantity) {
            $this->calculateVariance();
        }

        // Update crop cycle totals
        $this->updateCropCycleTotals();

        // Trigger ecosystem events
        $this->publishHarvestEvent();

        return true;
    }

    /**
     * Reject harvest entry
     */
    public function reject(string $userId, string $reason): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update([
            'status' => 'rejected',
            'approved_by' => $userId,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return true;
    }

    /**
     * Calculate variance percentage
     */
    public function calculateVariance(): void
    {
        if (!$this->expected_quantity || $this->expected_quantity == 0) {
            $this->variance_percentage = null;
            return;
        }

        $variance = (($this->total_quantity - $this->expected_quantity) / $this->expected_quantity) * 100;
        $this->update(['variance_percentage' => round($variance, 2)]);
    }

    /**
     * Update crop cycle harvest totals
     */
    public function updateCropCycleTotals(): void
    {
        if (!$this->cropCycle) {
            return;
        }

        $harvests = static::approved()
            ->forCrop($this->crop_cycle_id)
            ->get(['total_quantity', 'unit']);

        $normalizeUnit = static fn (?string $unit): string => match (strtolower(trim((string) $unit))) {
            'kilogram', 'kilograms', 'kgs' => 'kg',
            'ton', 'tons', 'tonne' => 'tonnes',
            default => strtolower(trim((string) $unit)),
        };

        $yieldUnit = $normalizeUnit($this->cropCycle->yield_unit ?: 'kg');
        $actualYield = $harvests
            ->filter(fn (Harvest $harvest) => $normalizeUnit($harvest->unit) === $yieldUnit)
            ->sum(fn (Harvest $harvest) => (float) $harvest->total_quantity);
        $actualYieldKg = $harvests
            ->filter(fn (Harvest $harvest) => $normalizeUnit($harvest->unit) === 'kg')
            ->sum(fn (Harvest $harvest) => (float) $harvest->total_quantity);

        $this->cropCycle->update([
            'actual_yield' => $actualYield,
            'actual_yield_kg' => $actualYieldKg,
        ]);
    }

    /**
     * Add bed-specific harvest data
     */
    public function addBedHarvest(array $bedData): HarvestBed
    {
        $bedHarvest = $this->harvestBeds()->create([
            'id' => \Str::uuid(),
            'bed_id' => $bedData['bed_id'] ?? null,
            'bed_name' => $bedData['bed_name'],
            'quantity' => $bedData['quantity'],
            'unit' => $bedData['unit'] ?? $this->unit,
            'grade' => $bedData['grade'] ?? $this->getGradeValue(),
            'area_harvested' => $bedData['area_harvested'] ?? null,
            'bed_notes' => $bedData['bed_notes'] ?? null,
            'bed_conditions' => $bedData['bed_conditions'] ?? null,
        ]);

        // Calculate yield per square meter if area provided
        if ($bedHarvest->area_harvested && $bedHarvest->area_harvested > 0) {
            $bedHarvest->update([
                'yield_per_sqm' => $bedHarvest->quantity / $bedHarvest->area_harvested
            ]);
        }

        return $bedHarvest;
    }

    /**
     * Add harvest note
     */
    public function addNote(array $noteData): HarvestNote
    {
        return $this->harvestNotes()->create([
            'id' => \Str::uuid(),
            'note' => $noteData['note'],
            'note_type' => $noteData['note_type'] ?? 'general',
            'created_by' => $noteData['created_by'],
            'metadata' => $noteData['metadata'] ?? null,
            'is_critical' => $noteData['is_critical'] ?? false,
        ]);
    }

    /**
     * Allocate harvest to sales/storage
     */
    public function allocateToSales(array $allocationData): HarvestSalesAllocation
    {
        $allocation = $this->salesAllocations()->create([
            'id' => \Str::uuid(),
            'allocation_type' => $allocationData['allocation_type'],
            'allocated_quantity' => $allocationData['allocated_quantity'],
            'unit' => $allocationData['unit'] ?? $this->unit,
            'destination' => $allocationData['destination'] ?? null,
            'price_per_unit' => $allocationData['price_per_unit'] ?? null,
            'allocation_date' => $allocationData['allocation_date'] ?? now(),
            'status' => $allocationData['status'] ?? 'pending',
            'notes' => $allocationData['notes'] ?? null,
            'created_by' => $allocationData['created_by'],
        ]);

        // Calculate total value for sales
        if ($allocation->allocation_type === 'sale' && $allocation->price_per_unit) {
            $allocation->update([
                'total_value' => $allocation->allocated_quantity * $allocation->price_per_unit
            ]);
        }

        return $allocation;
    }

    /**
     * Get total allocated quantity
     */
    public function getTotalAllocatedQuantity(): float
    {
        $allocationQuantity = (float) $this->salesAllocations()
            ->whereIn('status', ['pending', 'confirmed', 'delivered'])
            ->sum('allocated_quantity');
        $soldQuantity = (float) $this->sales()
            ->where('status', '!=', 'rejected')
            ->sum('quantity_sold');

        return $allocationQuantity + $soldQuantity;
    }

    /**
     * Get remaining quantity available for allocation
     */
    public function getRemainingQuantity(): float
    {
        return max(0, (float) $this->total_quantity - $this->getTotalAllocatedQuantity());
    }

    /**
     * Get grade value based on grade type
     */
    public function getGradeValue(): ?string
    {
        return $this->grade_type === 'simple' ? $this->simple_grade : $this->detailed_grade;
    }

    /**
     * Check if harvest is overdue (past expected harvest window)
     */
    public function isOverdue(): bool
    {
        if (!$this->cropCycle || !$this->cropCycle->expected_harvest_date) {
            return false;
        }

        return $this->harvest_date > $this->cropCycle->expected_harvest_date;
    }

    /**
     * Get yield performance score
     */
    public function getYieldPerformanceScore(): ?string
    {
        if ($this->variance_percentage === null) {
            return null;
        }

        if ($this->variance_percentage >= 20) return 'Excellent';
        if ($this->variance_percentage >= 10) return 'Very Good';
        if ($this->variance_percentage >= 0) return 'Good';
        if ($this->variance_percentage >= -10) return 'Below Expected';
        if ($this->variance_percentage >= -25) return 'Poor';
        return 'Very Poor';
    }

    /**
     * Close crop cycle if this is final harvest
     */
    public function closeCropCycleIfFinal(): void
    {
        if (!$this->is_final_harvest || !$this->cropCycle) {
            return;
        }

        $this->cropCycle->update([
            'status' => 'completed',
            'season_status' => 'completed',
            'actual_harvest_date' => $this->harvest_date,
            'completed_at' => now(),
        ]);

        // Generate profit summary
        $this->generateProfitSummary();
    }

    /**
     * Generate profit summary for completed crop
     */
    protected function generateProfitSummary(): void
    {
        $totalRevenue = $this->cropCycle->harvests()
            ->approved()
            ->with('salesAllocations')
            ->get()
            ->flatMap->salesAllocations
            ->where('allocation_type', 'sale')
            ->sum('total_value');

        $totalExpenses = $this->cropCycle->expenses()
            ->approved()
            ->where(function ($query) {
                $query->whereDoesntHave('category')
                    ->orWhereHas('category', fn ($category) => $category->where('slug', '!=', 'labour-wages'));
            })
            ->sum('amount');
        $totalLabour = $this->cropCycle->labourEntries()->approved()->sum('amount');

        $this->cropCycle->update([
            'actual_revenue' => $totalRevenue,
            'actual_cost' => $totalExpenses + $totalLabour,
        ]);
    }

    /**
     * Publish harvest event to ecosystem
     */
    protected function publishHarvestEvent(): void
    {
        if (app()->bound('ecosystem.events')) {
            app('ecosystem.events')->handleHarvestCompletion($this);
        }
    }

    // Analytics Methods

    /**
     * Get harvest analytics for date range
     */
    public static function getHarvestAnalytics(string $cropCycleId, $startDate = null, $endDate = null): array
    {
        $query = static::approved()->forCrop($cropCycleId);

        if ($startDate && $endDate) {
            $query->inDateRange($startDate, $endDate);
        }

        $harvests = $query->with(['harvestBeds', 'salesAllocations'])->get();

        return [
            'total_harvests' => $harvests->count(),
            'total_quantity' => $harvests->sum('total_quantity'),
            'average_quantity_per_harvest' => $harvests->avg('total_quantity'),
            'total_allocated' => $harvests->sum(fn($h) => $h->getTotalAllocatedQuantity()),
            'grade_distribution' => static::getGradeDistribution($harvests),
            'yield_variance' => [
                'average' => $harvests->whereNotNull('variance_percentage')->avg('variance_percentage'),
                'best' => $harvests->whereNotNull('variance_percentage')->max('variance_percentage'),
                'worst' => $harvests->whereNotNull('variance_percentage')->min('variance_percentage'),
            ],
            'bed_performance' => static::getBedPerformance($harvests),
        ];
    }

    /**
     * Get grade distribution
     */
    protected static function getGradeDistribution($harvests): array
    {
        return [
            'marketable' => $harvests->where(fn($h) => $h->getGradeValue() === 'marketable')->sum('total_quantity'),
            'non_marketable' => $harvests->where(fn($h) => $h->getGradeValue() === 'non_marketable')->sum('total_quantity'),
            'grade_a' => $harvests->where(fn($h) => $h->getGradeValue() === 'A')->sum('total_quantity'),
            'grade_b' => $harvests->where(fn($h) => $h->getGradeValue() === 'B')->sum('total_quantity'),
            'grade_c' => $harvests->where(fn($h) => $h->getGradeValue() === 'C')->sum('total_quantity'),
            'reject' => $harvests->where(fn($h) => $h->getGradeValue() === 'reject')->sum('total_quantity'),
        ];
    }

    /**
     * Get bed performance analytics
     */
    protected static function getBedPerformance($harvests): array
    {
        $bedData = [];
        
        foreach ($harvests as $harvest) {
            foreach ($harvest->harvestBeds as $bed) {
                if (!isset($bedData[$bed->bed_name])) {
                    $bedData[$bed->bed_name] = [
                        'total_quantity' => 0,
                        'harvest_count' => 0,
                        'total_area' => 0,
                        'average_yield_per_sqm' => 0,
                    ];
                }

                $bedData[$bed->bed_name]['total_quantity'] += $bed->quantity;
                $bedData[$bed->bed_name]['harvest_count']++;
                
                if ($bed->area_harvested) {
                    $bedData[$bed->bed_name]['total_area'] = max(
                        $bedData[$bed->bed_name]['total_area'], 
                        $bed->area_harvested
                    );
                }
            }
        }

        // Calculate average yield per sqm for each bed
        foreach ($bedData as $bedName => &$data) {
            if ($data['total_area'] > 0) {
                $data['average_yield_per_sqm'] = $data['total_quantity'] / $data['total_area'];
            }
        }

        return $bedData;
    }
}
