<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HarvestSalesAllocation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'harvest_id',
        'allocation_type',
        'allocated_quantity',
        'unit',
        'destination',
        'price_per_unit',
        'total_value',
        'allocation_date',
        'status',
        'notes',
        'created_by'
    ];

    protected $casts = [
        'allocated_quantity' => 'decimal:3',
        'price_per_unit' => 'decimal:2',
        'total_value' => 'decimal:2',
        'allocation_date' => 'date',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    // Relationships
    public function harvest(): BelongsTo
    {
        return $this->belongsTo(Harvest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeByType($query, string $type)
    {
        return $query->where('allocation_type', $type);
    }

    public function scopeSales($query)
    {
        return $query->where('allocation_type', 'sale');
    }

    public function scopeStorage($query)
    {
        return $query->where('allocation_type', 'storage');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    public function scopeForHarvest($query, string $harvestId)
    {
        return $query->where('harvest_id', $harvestId);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('allocation_date', [$startDate, $endDate]);
    }

    // Business Logic Methods

    /**
     * Calculate total value automatically
     */
    public function calculateTotalValue(): void
    {
        if ($this->price_per_unit && $this->allocated_quantity) {
            $this->update([
                'total_value' => $this->price_per_unit * $this->allocated_quantity
            ]);
        }
    }

    /**
     * Confirm allocation
     */
    public function confirm(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $this->update(['status' => 'confirmed']);
        return true;
    }

    /**
     * Mark as delivered
     */
    public function markDelivered(): bool
    {
        if ($this->status !== 'confirmed') {
            return false;
        }

        $this->update(['status' => 'delivered']);
        
        // If this is a sale, trigger revenue tracking
        if ($this->allocation_type === 'sale') {
            $this->createSalesRecord();
        }

        return true;
    }

    /**
     * Create sales record for delivered allocations
     */
    protected function createSalesRecord(): void
    {
        // This would integrate with Module 7 (Sales & Income)
        // For now, we'll just log the event
        \Log::info('Sale delivered', [
            'allocation_id' => $this->id,
            'harvest_id' => $this->harvest_id,
            'quantity' => $this->allocated_quantity,
            'value' => $this->total_value,
            'destination' => $this->destination,
        ]);
    }

    /**
     * Validate allocation against available quantity
     */
    public function validateAllocation(): array
    {
        $harvest = $this->harvest;
        
        if (!$harvest) {
            return [
                'valid' => false,
                'error' => 'Harvest not found'
            ];
        }

        $totalAllocated = $harvest->salesAllocations()
            ->where('id', '!=', $this->id)
            ->sum('allocated_quantity');

        $availableQuantity = $harvest->total_quantity - $totalAllocated;

        if ($this->allocated_quantity > $availableQuantity) {
            return [
                'valid' => false,
                'error' => 'Allocated quantity exceeds available quantity',
                'available_quantity' => $availableQuantity,
                'requested_quantity' => $this->allocated_quantity,
                'excess' => $this->allocated_quantity - $availableQuantity
            ];
        }

        return [
            'valid' => true,
            'available_quantity' => $availableQuantity,
            'remaining_after_allocation' => $availableQuantity - $this->allocated_quantity
        ];
    }

    /**
     * Get allocation summary for a harvest
     */
    public static function getAllocationSummary(string $harvestId): array
    {
        $allocations = static::forHarvest($harvestId)->get();

        return [
            'total_allocations' => $allocations->count(),
            'total_allocated_quantity' => $allocations->sum('allocated_quantity'),
            'total_value' => $allocations->sales()->sum('total_value'),
            'by_type' => [
                'sale' => $allocations->byType('sale')->sum('allocated_quantity'),
                'storage' => $allocations->byType('storage')->sum('allocated_quantity'),
                'personal_use' => $allocations->byType('personal_use')->sum('allocated_quantity'),
                'loss' => $allocations->byType('loss')->sum('allocated_quantity'),
            ],
            'by_status' => [
                'pending' => $allocations->pending()->count(),
                'confirmed' => $allocations->confirmed()->count(),
                'delivered' => $allocations->delivered()->count(),
            ],
            'pending_value' => $allocations->pending()->sales()->sum('total_value'),
            'delivered_value' => $allocations->delivered()->sales()->sum('total_value'),
        ];
    }

    /**
     * Get sales performance analytics
     */
    public static function getSalesAnalytics($startDate = null, $endDate = null): array
    {
        $query = static::sales();

        if ($startDate && $endDate) {
            $query->inDateRange($startDate, $endDate);
        }

        $salesAllocations = $query->get();

        $totalSalesValue = $salesAllocations->sum('total_value');
        $deliveredValue = $salesAllocations->delivered()->sum('total_value');

        return [
            'total_sales_allocations' => $salesAllocations->count(),
            'total_sales_value' => $totalSalesValue,
            'delivered_sales_value' => $deliveredValue,
            'pending_sales_value' => $salesAllocations->pending()->sum('total_value'),
            'average_price_per_unit' => $salesAllocations->whereNotNull('price_per_unit')->avg('price_per_unit'),
            'delivery_rate' => $salesAllocations->count() > 0 ? 
                ($salesAllocations->delivered()->count() / $salesAllocations->count()) * 100 : 0,
            'top_destinations' => static::getTopDestinations($salesAllocations),
            'monthly_trends' => static::getMonthlySalesTrends($salesAllocations),
        ];
    }

    /**
     * Get top sales destinations
     */
    protected static function getTopDestinations($salesAllocations): array
    {
        return $salesAllocations
            ->whereNotNull('destination')
            ->groupBy('destination')
            ->map(function($allocations, $destination) {
                return [
                    'destination' => $destination,
                    'total_quantity' => $allocations->sum('allocated_quantity'),
                    'total_value' => $allocations->sum('total_value'),
                    'allocation_count' => $allocations->count(),
                    'average_price' => $allocations->avg('price_per_unit'),
                ];
            })
            ->sortByDesc('total_value')
            ->take(5)
            ->values()
            ->toArray();
    }

    /**
     * Get monthly sales trends
     */
    protected static function getMonthlySalesTrends($salesAllocations): array
    {
        return $salesAllocations
            ->groupBy(function($allocation) {
                return $allocation->allocation_date->format('Y-m');
            })
            ->map(function($monthAllocations, $month) {
                return [
                    'month' => $month,
                    'total_quantity' => $monthAllocations->sum('allocated_quantity'),
                    'total_value' => $monthAllocations->sum('total_value'),
                    'allocation_count' => $monthAllocations->count(),
                    'average_price' => $monthAllocations->avg('price_per_unit'),
                ];
            })
            ->sortBy('month')
            ->values()
            ->toArray();
    }
}