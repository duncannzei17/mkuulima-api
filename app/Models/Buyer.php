<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use App\Events\BuyerCreated;
use Illuminate\Support\Facades\Crypt;

class Buyer extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'type',
        'phone',
        'phone_encrypted',
        'phone_last_four',
        'email',
        'location',
        'address',
        'notes',
        'credit_limit',
        'payment_terms',
        'payment_days',
        'is_active',
        'contact_info',
        'total_purchases',
        'total_transactions',
        'average_purchase_value',
        'last_purchase_date',
        'reliability_score',
        'created_by'
    ];

    protected $hidden = ['phone_encrypted'];

    protected $appends = ['phone'];

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'total_purchases' => 'decimal:2',
        'average_purchase_value' => 'decimal:2',
        'reliability_score' => 'decimal:2',
        'total_transactions' => 'integer',
        'payment_days' => 'integer',
        'is_active' => 'boolean',
        'contact_info' => 'array',
        'last_purchase_date' => 'date',
    ];

    protected $attributes = [
        'type' => 'individual',
        'payment_terms' => 'immediate',
        'payment_days' => 0,
        'is_active' => true,
        'total_purchases' => 0,
        'total_transactions' => 0,
        'average_purchase_value' => 0,
        'reliability_score' => 5.0,
        'credit_limit' => 0,
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function (Buyer $buyer) {
            event(new BuyerCreated($buyer));
        });
    }

    public function setPhoneAttribute(?string $value): void
    {
        $normalized = $value ? trim($value) : null;
        $this->attributes['phone'] = null;
        $this->attributes['phone_encrypted'] = $normalized ? Crypt::encryptString($normalized) : null;
        $this->attributes['phone_last_four'] = $normalized ? substr($normalized, -4) : null;
    }

    public function getPhoneAttribute(?string $value): ?string
    {
        if (! empty($this->attributes['phone_encrypted'])) {
            try {
                return Crypt::decryptString($this->attributes['phone_encrypted']);
            } catch (\Throwable) {
                return null;
            }
        }

        return $value;
    }

    // Relationships
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(BuyerOffer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByLocation($query, string $location)
    {
        return $query->where('location', 'LIKE', "%{$location}%");
    }

    public function scopeReliable($query, float $minScore = 4.0)
    {
        return $query->where('reliability_score', '>=', $minScore);
    }

    public function scopeRegular($query, int $minTransactions = 5)
    {
        return $query->where('total_transactions', '>=', $minTransactions);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function($q) use ($term) {
            $q->where('name', 'LIKE', "%{$term}%")
              ->orWhere('phone_last_four', substr($term, -4))
              ->orWhere('location', 'LIKE', "%{$term}%");
        });
    }

    // Business Logic Methods

    /**
     * Update buyer statistics after a sale
     */
    public function updateStatistics(Sale $sale): void
    {
        if ($sale->status !== 'approved') {
            return;
        }

        $stats = $this->sales()
            ->where('status', 'approved')
            ->select([
                DB::raw('COUNT(*) as transaction_count'),
                DB::raw('SUM(net_income) as total_value'),
                DB::raw('AVG(net_income) as avg_value'),
                DB::raw('MAX(sale_date) as last_sale')
            ])
            ->first();

        $this->update([
            'total_transactions' => $stats->transaction_count ?? 0,
            'total_purchases' => $stats->total_value ?? 0,
            'average_purchase_value' => $stats->avg_value ?? 0,
            'last_purchase_date' => $stats->last_sale,
        ]);
    }

    /**
     * Calculate and update reliability score
     */
    public function updateReliabilityScore(): void
    {
        $recentSales = $this->sales()
            ->where('status', 'approved')
            ->where('sale_date', '>=', now()->subMonths(6))
            ->get();

        if ($recentSales->isEmpty()) {
            return;
        }

        $factors = [
            'payment_reliability' => $this->calculatePaymentReliability($recentSales),
            'order_consistency' => $this->calculateOrderConsistency($recentSales),
            'volume_stability' => $this->calculateVolumeStability($recentSales),
            'price_fairness' => $this->calculatePriceFairness($recentSales),
        ];

        $averageScore = array_sum($factors) / count($factors);
        
        $this->update(['reliability_score' => round($averageScore, 2)]);
    }

    /**
     * Calculate payment reliability score
     */
    protected function calculatePaymentReliability($sales): float
    {
        $totalSales = $sales->count();
        $paidOnTime = $sales->where('payment_status', 'paid')->count();
        
        if ($totalSales === 0) return 5.0;
        
        $reliability = ($paidOnTime / $totalSales) * 5;
        return min(5.0, max(1.0, $reliability));
    }

    /**
     * Calculate order consistency score
     */
    protected function calculateOrderConsistency($sales): float
    {
        $monthlyOrders = $sales->groupBy(function($sale) {
            return $sale->sale_date->format('Y-m');
        })->map->count();

        $variance = $this->calculateVariance($monthlyOrders->toArray());
        $avgOrders = $monthlyOrders->avg();

        if ($avgOrders == 0) return 5.0;

        $consistency = max(1.0, 5.0 - ($variance / $avgOrders));
        return min(5.0, $consistency);
    }

    /**
     * Calculate volume stability score
     */
    protected function calculateVolumeStability($sales): float
    {
        $quantities = $sales->pluck('quantity_sold')->toArray();
        
        if (empty($quantities)) return 5.0;

        $variance = $this->calculateVariance($quantities);
        $avgQuantity = array_sum($quantities) / count($quantities);

        if ($avgQuantity == 0) return 5.0;

        $stability = max(1.0, 5.0 - ($variance / $avgQuantity));
        return min(5.0, $stability);
    }

    /**
     * Calculate price fairness score
     */
    protected function calculatePriceFairness($sales): float
    {
        // Compare buyer's prices with market average
        $avgBuyerPrice = $sales->avg('price_per_unit');
        
        // Get market average for same period and crops
        $marketAvg = PriceHistory::whereIn('crop_name', 
            $sales->pluck('cropCycle.crop_name')->unique()
        )->whereBetween('price_date', [
            $sales->min('sale_date'),
            $sales->max('sale_date')
        ])->avg('price_per_unit');

        if (!$marketAvg || $marketAvg == 0) return 5.0;

        $priceRatio = $avgBuyerPrice / $marketAvg;
        
        // Score based on how close to market rate
        if ($priceRatio >= 0.95) return 5.0; // Pays well
        if ($priceRatio >= 0.85) return 4.0;
        if ($priceRatio >= 0.75) return 3.0;
        if ($priceRatio >= 0.65) return 2.0;
        return 1.0; // Pays poorly
    }

    /**
     * Calculate variance of an array
     */
    protected function calculateVariance(array $values): float
    {
        if (count($values) <= 1) return 0;

        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(function($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $values)) / count($values);

        return $variance;
    }

    /**
     * Get buyer's outstanding credit amount
     */
    public function getOutstandingCredit(): float
    {
        return $this->sales()
            ->where('status', 'approved')
            ->where('payment_status', '!=', 'paid')
            ->sum('amount_outstanding');
    }

    /**
     * Check if buyer can make a purchase given credit limit
     */
    public function canPurchase(float $amount): bool
    {
        if ($this->payment_terms === 'immediate') {
            return true; // No credit check needed for immediate payment
        }

        $outstanding = $this->getOutstandingCredit();
        return ($outstanding + $amount) <= $this->credit_limit;
    }

    /**
     * Get buyer's purchase history analysis
     */
    public function getPurchaseAnalysis(): array
    {
        $sales = $this->sales()
            ->where('status', 'approved')
            ->with('cropCycle')
            ->get();

        $preferredCrops = $sales->groupBy('cropCycle.crop_name')
            ->map(function($cropSales) {
                return [
                    'total_quantity' => $cropSales->sum('quantity_sold'),
                    'total_value' => $cropSales->sum('net_income'),
                    'average_price' => $cropSales->avg('price_per_unit'),
                    'purchase_count' => $cropSales->count(),
                ];
            })
            ->sortByDesc('total_value');

        $monthlyTrends = $sales->groupBy(function($sale) {
            return $sale->sale_date->format('Y-m');
        })->map(function($monthSales) {
            return [
                'total_purchases' => $monthSales->count(),
                'total_quantity' => $monthSales->sum('quantity_sold'),
                'total_value' => $monthSales->sum('net_income'),
                'average_price' => $monthSales->avg('price_per_unit'),
            ];
        })->sortBy('month');

        return [
            'total_lifetime_value' => $this->total_purchases,
            'total_transactions' => $this->total_transactions,
            'average_purchase_value' => $this->average_purchase_value,
            'last_purchase_date' => $this->last_purchase_date,
            'reliability_score' => $this->reliability_score,
            'outstanding_credit' => $this->getOutstandingCredit(),
            'credit_utilization' => $this->credit_limit > 0 ? 
                ($this->getOutstandingCredit() / $this->credit_limit) * 100 : 0,
            'preferred_crops' => $preferredCrops->take(5)->toArray(),
            'monthly_trends' => $monthlyTrends->toArray(),
        ];
    }

    /**
     * Deactivate buyer
     */
    public function deactivate(string $reason = null): void
    {
        $this->update([
            'is_active' => false,
            'notes' => $this->notes . "\n\nDeactivated: " . $reason . " (" . now()->format('Y-m-d') . ")"
        ]);
    }

    /**
     * Reactivate buyer
     */
    public function reactivate(): void
    {
        $this->update(['is_active' => true]);
    }

    /**
     * Get top buyers by various criteria
     */
    public static function getTopBuyers(string $criteria = 'value', int $limit = 10): array
    {
        $query = static::active();

        switch ($criteria) {
            case 'value':
                $query->orderByDesc('total_purchases');
                break;
            case 'frequency':
                $query->orderByDesc('total_transactions');
                break;
            case 'reliability':
                $query->orderByDesc('reliability_score');
                break;
            case 'average_order':
                $query->orderByDesc('average_purchase_value');
                break;
        }

        return $query->limit($limit)->get()->map(function($buyer) {
            return [
                'id' => $buyer->id,
                'name' => $buyer->name,
                'type' => $buyer->type,
                'location' => $buyer->location,
                'total_purchases' => $buyer->total_purchases,
                'total_transactions' => $buyer->total_transactions,
                'average_purchase_value' => $buyer->average_purchase_value,
                'reliability_score' => $buyer->reliability_score,
                'last_purchase_date' => $buyer->last_purchase_date,
            ];
        })->toArray();
    }
}
