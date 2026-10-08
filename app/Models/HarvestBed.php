<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HarvestBed extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'harvest_id',
        'bed_id',
        'bed_name',
        'quantity',
        'unit',
        'grade',
        'area_harvested',
        'yield_per_sqm',
        'bed_notes',
        'bed_conditions'
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'area_harvested' => 'decimal:2',
        'yield_per_sqm' => 'decimal:3',
        'bed_conditions' => 'array',
    ];

    // Relationships
    public function harvest(): BelongsTo
    {
        return $this->belongsTo(Harvest::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    // Scopes
    public function scopeByBed($query, string $bedName)
    {
        return $query->where('bed_name', $bedName);
    }

    public function scopeForBed($query, string $bedId)
    {
        return $query->where('bed_id', $bedId);
    }

    public function scopeByGrade($query, string $grade)
    {
        return $query->where('grade', $grade);
    }

    public function scopeWithYield($query)
    {
        return $query->whereNotNull('yield_per_sqm');
    }

    // Business Logic Methods

    /**
     * Calculate yield per square meter
     */
    public function calculateYieldPerSqm(): ?float
    {
        if (!$this->area_harvested || $this->area_harvested <= 0) {
            return null;
        }

        $yield = $this->quantity / $this->area_harvested;
        $this->update(['yield_per_sqm' => $yield]);
        
        return $yield;
    }

    /**
     * Get yield efficiency compared to farm average
     */
    public function getYieldEfficiency(): ?array
    {
        if (!$this->yield_per_sqm) {
            return null;
        }

        // Get average yield for this bed across all harvests
        $averageYield = static::byBed($this->bed_name)
            ->withYield()
            ->avg('yield_per_sqm');

        if (!$averageYield) {
            return null;
        }

        $efficiency = (($this->yield_per_sqm - $averageYield) / $averageYield) * 100;

        return [
            'current_yield' => $this->yield_per_sqm,
            'average_yield' => $averageYield,
            'efficiency_percentage' => round($efficiency, 2),
            'performance' => $this->getPerformanceRating($efficiency),
        ];
    }

    /**
     * Get performance rating based on efficiency
     */
    protected function getPerformanceRating(float $efficiency): string
    {
        if ($efficiency >= 20) return 'Excellent';
        if ($efficiency >= 10) return 'Very Good';
        if ($efficiency >= 0) return 'Average';
        if ($efficiency >= -10) return 'Below Average';
        if ($efficiency >= -25) return 'Poor';
        return 'Very Poor';
    }

    /**
     * Get bed harvest history
     */
    public static function getBedHistory(string $bedName, int $limit = 10): array
    {
        $bedHarvests = static::byBed($bedName)
            ->with(['harvest' => function($query) {
                $query->approved()->orderBy('harvest_date', 'desc');
            }])
            ->limit($limit)
            ->get();

        return [
            'bed_name' => $bedName,
            'total_harvests' => $bedHarvests->count(),
            'total_quantity' => $bedHarvests->sum('quantity'),
            'average_quantity' => $bedHarvests->avg('quantity'),
            'average_yield_per_sqm' => $bedHarvests->withYield()->avg('yield_per_sqm'),
            'recent_harvests' => $bedHarvests->map(function($bed) {
                return [
                    'harvest_date' => $bed->harvest->harvest_date->format('Y-m-d'),
                    'quantity' => $bed->quantity,
                    'unit' => $bed->unit,
                    'grade' => $bed->grade,
                    'yield_per_sqm' => $bed->yield_per_sqm,
                    'notes' => $bed->bed_notes,
                ];
            }),
        ];
    }

    /**
     * Get top performing beds
     */
    public static function getTopPerformingBeds(int $limit = 5): array
    {
        return static::withYield()
            ->selectRaw('bed_name, AVG(yield_per_sqm) as avg_yield, COUNT(*) as harvest_count, SUM(quantity) as total_quantity')
            ->groupBy('bed_name')
            ->havingRaw('COUNT(*) >= 3') // At least 3 harvests for reliable data
            ->orderBy('avg_yield', 'desc')
            ->limit($limit)
            ->get()
            ->map(function($bed) {
                return [
                    'bed_name' => $bed->bed_name,
                    'average_yield_per_sqm' => round($bed->avg_yield, 3),
                    'harvest_count' => $bed->harvest_count,
                    'total_quantity' => $bed->total_quantity,
                ];
            })
            ->toArray();
    }
}
