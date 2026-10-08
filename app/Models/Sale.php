<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Events\SaleCreated;
use App\Events\SaleApproved;

class Sale extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'crop_cycle_id',
        'harvest_id',
        'sale_date',
        'quantity_sold',
        'unit',
        'price_per_unit',
        'gross_income',
        'total_deductions',
        'net_income',
        'buyer_id',
        'buyer_name',
        'market_location',
        'sale_type',
        'payment_method',
        'payment_status',
        'payment_due_date',
        'amount_paid',
        'amount_outstanding',
        'notes',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'delivery_info',
        'transport_cost',
        'is_recurring_buyer',
        'quality_feedback',
    ];

    protected $casts = [
        'sale_date' => 'date',
        'quantity_sold' => 'decimal:3',
        'price_per_unit' => 'decimal:2',
        'gross_income' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_income' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'amount_outstanding' => 'decimal:2',
        'transport_cost' => 'decimal:2',
        'payment_due_date' => 'date',
        'approved_at' => 'timestamp',
        'delivery_info' => 'array',
        'quality_feedback' => 'array',
        'is_recurring_buyer' => 'boolean',
    ];

    protected $attributes = [
        'sale_type' => 'market',
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'status' => 'approved',
        'total_deductions' => 0,
        'amount_paid' => 0,
        'amount_outstanding' => 0,
        'transport_cost' => 0,
        'is_recurring_buyer' => false,
    ];

    protected static function boot()
    {
        parent::boot();

        static::created(function (Sale $sale) {
            event(new SaleCreated($sale));
        });
    }

    // Buyer types
    public const BUYER_TYPES = [
        'individual' => 'Individual Consumer',
        'retailer' => 'Retailer',
        'wholesaler' => 'Wholesaler',
        'processor' => 'Processor/Factory',
        'exporter' => 'Exporter',
        'cooperative' => 'Cooperative',
        'government' => 'Government',
        'institution' => 'Institution',
        'other' => 'Other'
    ];

    // Quality grades
    public const QUALITY_GRADES = [
        'premium' => 'Premium (AAA)',
        'grade_a' => 'Grade A',
        'grade_b' => 'Grade B',
        'grade_c' => 'Grade C',
        'mixed' => 'Mixed Quality',
        'seconds' => 'Seconds',
        'reject' => 'Reject/Low Grade'
    ];

    // Payment methods (same as Expense)
    public const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'mpesa' => 'M-Pesa',
        'bank_transfer' => 'Bank Transfer',
        'cheque' => 'Cheque',
        'credit' => 'Credit/Account',
        'mobile_money' => 'Mobile Money',
        'other' => 'Other'
    ];

    // Units of measurement
    public const UNITS = [
        'kg' => 'Kilograms',
        'tonnes' => 'Tonnes',
        'bags' => 'Bags',
        'crates' => 'Crates',
        'boxes' => 'Boxes',
        'bunches' => 'Bunches',
        'pieces' => 'Pieces',
        'litres' => 'Litres',
        'sacks' => 'Sacks',
        'other' => 'Other'
    ];

    // Relationships
    public function deductions(): HasMany
    {
        return $this->hasMany(SalesDeduction::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(SalesApproval::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class)->orderByDesc('payment_date');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function harvest(): BelongsTo
    {
        return $this->belongsTo(Harvest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Legacy relationships for backward compatibility
    public function farm()
    {
        return $this->belongsTo(Farm::class);
    }

    public function crop()
    {
        return $this->belongsTo(Crop::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
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

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPaymentStatus($query, string $paymentStatus)
    {
        return $query->where('payment_status', $paymentStatus);
    }

    public function scopeByDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('sale_date', [$startDate, $endDate]);
    }

    public function scopeByBuyer($query, string $buyerId)
    {
        return $query->where('buyer_id', $buyerId);
    }

    public function scopeBySaleType($query, string $saleType)
    {
        return $query->where('sale_type', $saleType);
    }

    public function scopeOverdue($query)
    {
        return $query->where('payment_status', '!=', 'paid')
                    ->where('payment_due_date', '<', now());
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function($q) use ($term) {
            $q->where('buyer_name', 'LIKE', "%{$term}%")
              ->orWhere('market_location', 'LIKE', "%{$term}%")
              ->orWhere('notes', 'LIKE', "%{$term}%");
        });
    }

    // Legacy scopes for backward compatibility
    public function scopeByFarm($query, $farmId)
    {
        return $query->where('farm_id', $farmId);
    }

    public function scopeByCrop($query, $cropId)
    {
        return $query->where('crop_id', $cropId);
    }

    public function scopeByBuyerType($query, $buyerType)
    {
        return $query->where('buyer_type', $buyerType);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('sale_date', now()->month)
                    ->whereYear('sale_date', now()->year);
    }

    public function scopeThisYear($query)
    {
        return $query->whereYear('sale_date', now()->year);
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    public function scopeDelivered($query)
    {
        return $query->where('delivery_status', 'delivered');
    }

    public function scopePremiumGrade($query)
    {
        return $query->where('quality_grade', 'premium');
    }

    // Accessors & Mutators
    public function getFormattedAmountAttribute()
    {
        return number_format((float) $this->gross_income, 2) . ' KES';
    }

    public function getTotalAmountAttribute()
    {
        return $this->gross_income;
    }

    public function getNetAmountAttribute()
    {
        return $this->net_income;
    }

    public function getUnitPriceAttribute()
    {
        return $this->price_per_unit;
    }

    public function getCurrencyAttribute(): string
    {
        return 'KES';
    }

    public function getBuyerTypeNameAttribute()
    {
        return self::BUYER_TYPES[$this->buyer_type] ?? $this->buyer_type;
    }

    public function getQualityGradeNameAttribute()
    {
        return self::QUALITY_GRADES[$this->quality_grade] ?? $this->quality_grade;
    }

    public function getPaymentMethodNameAttribute()
    {
        return self::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method;
    }

    public function getUnitNameAttribute()
    {
        return self::UNITS[$this->unit] ?? $this->unit;
    }

    public function getProfitMarginAttribute()
    {
        if ($this->price_per_unit > 0) {
            // This would require cost data from expenses or crop costs
            // Simplified calculation for now
            return 0; // Implement based on actual cost tracking
        }
        return 0;
    }

    // Mutators for automatic calculations
    public function setQuantitySoldAttribute($value)
    {
        $this->attributes['quantity_sold'] = $value;
        $this->calculateTotalAmount();
    }

    public function setPricePerUnitAttribute($value)
    {
        $this->attributes['price_per_unit'] = $value;
        $this->calculateTotalAmount();
    }

    public function setUnitPriceAttribute($value)
    {
        $this->attributes['price_per_unit'] = $value;
        $this->calculateTotalAmount();
    }

    public function setCommissionRateAttribute($value)
    {
        // Legacy input is intentionally ignored; deductions are the canonical workflow.
    }

    public function setTransportCostAttribute($value)
    {
        $this->attributes['transport_cost'] = $value;
    }

    // Business Logic Methods

    private function calculateTotalAmount(): void
    {
        $quantity = (float) ($this->attributes['quantity_sold'] ?? 0);
        $price = (float) ($this->attributes['price_per_unit'] ?? $this->attributes['unit_price'] ?? 0);
        $gross = $quantity * $price;
        $deductions = (float) ($this->attributes['total_deductions'] ?? 0);
        $paid = (float) ($this->attributes['amount_paid'] ?? 0);

        if ($gross > 0) {
            $this->attributes['gross_income'] = $gross;
            $this->attributes['net_income'] = $gross - $deductions;
            $this->attributes['amount_outstanding'] = max(0, $this->attributes['net_income'] - $paid);
        }
    }
    
    /**
     * Calculate and update financial totals
     */
    public function calculateTotals(): void
    {
        $this->gross_income = $this->quantity_sold * $this->price_per_unit;
        $this->total_deductions = $this->deductions()->sum('amount');
        $this->net_income = $this->gross_income - $this->total_deductions;
        
        // Calculate outstanding amount
        $this->amount_outstanding = max(0, $this->net_income - $this->amount_paid);
        
        // Update payment status based on amounts
        if ($this->amount_paid >= $this->net_income) {
            $this->payment_status = 'paid';
        } elseif ($this->amount_paid > 0) {
            $this->payment_status = 'partial';
        } elseif ($this->payment_due_date && $this->payment_due_date->isPast()) {
            $this->payment_status = 'overdue';
        } else {
            $this->payment_status = 'pending';
        }
        
        $this->save();
        
    }

    /**
     * Add a deduction to this sale
     */
    public function addDeduction(string $type, float $amount, ?string $description = null, ?string $recipient = null): SalesDeduction
    {
        $deduction = $this->deductions()->create([
            'deduction_type' => $type,
            'amount' => $amount,
            'description' => $description,
            'recipient' => $recipient
        ]);

        $this->calculateTotals();
        
        return $deduction;
    }

    /**
     * Record a payment
     */
    public function recordPayment(float $amount, ?string $notes = null): void
    {
        $this->amount_paid += $amount;
        $this->calculateTotals();
        
        if ($notes) {
            $this->notes = ($this->notes ?? '') . "\nPayment recorded: {$amount} - {$notes}";
            $this->save();
        }
    }

    /**
     * Approve this sale
     */
    public function approve(string $userId, ?string $reason = null, ?array $changesMade = null): SalesApproval
    {
        $approval = $this->approvals()->create([
            'approved_by' => $userId,
            'action' => 'approved',
            'reason' => $reason,
            'changes_made' => $changesMade,
            'action_taken_at' => now()
        ]);

        $this->update([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now()
        ]);

        // Update buyer statistics if approved
        if ($this->buyer) {
            $this->buyer->updateStatistics($this);
        }

        // Create price history entry
        $this->createPriceHistoryEntry();

        // Dispatch sale approved event
        event(new SaleApproved($this, $approval));

        return $approval;
    }

    /**
     * Reject this sale
     */
    public function reject(string $userId, string $reason): SalesApproval
    {
        $approval = $this->approvals()->create([
            'approved_by' => $userId,
            'action' => 'rejected',
            'reason' => $reason,
            'action_taken_at' => now()
        ]);

        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason
        ]);

        return $approval;
    }

    /**
     * Create price history entry from this sale
     */
    public function createPriceHistoryEntry(): void
    {
        if ($this->status !== 'approved') {
            return;
        }

        // Get crop name from crop cycle if available
        $cropName = $this->cropCycle?->crop_name ?? 'unknown';

        PriceHistory::updateOrCreate(['sale_id' => $this->id], [
            'crop_name' => $cropName,
            'unit' => $this->unit,
            'price_per_unit' => $this->price_per_unit,
            'price_date' => $this->sale_date,
            'market_location' => $this->market_location,
            'price_type' => $this->determinePriceType(),
            'sale_id' => $this->id,
            'quantity_sold' => $this->quantity_sold,
            'season' => $this->determineSeason(),
            'created_by' => $this->created_by
        ]);
    }

    /**
     * Determine price type based on sale type
     */
    protected function determinePriceType(): string
    {
        return match($this->sale_type) {
            'farmgate' => 'farmgate',
            'market', 'door_to_door', 'pickup', 'delivery' => 'retail',
            'bulk_order', 'spot_sale' => 'wholesale',
            default => 'farmgate'
        };
    }

    /**
     * Determine season based on sale date
     */
    protected function determineSeason(): string
    {
        $month = $this->sale_date->month;
        
        // Adjust for local seasons
        if (in_array($month, [12, 1, 2])) {
            return 'dry';
        } elseif (in_array($month, [3, 4, 5])) {
            return 'harvest';
        } else {
            return 'wet';
        }
    }

    public function markAsPaid($paymentMethod = null)
    {
        $this->payment_status = 'paid';
        $this->amount_paid = $this->net_income;
        $this->amount_outstanding = 0;
        if ($paymentMethod) {
            $this->payment_method = $paymentMethod;
        }
        $this->save();
    }

    public function markAsDelivered($deliveryDate = null)
    {
        $this->delivery_info = array_merge($this->delivery_info ?? [], [
            'status' => 'delivered',
            'delivery_date' => $deliveryDate ?? now()->toDateString(),
            'delivered_at' => now()->toISOString(),
        ]);
        $this->save();
    }

    public function calculatePricePerUnit($baseUnit = 'kg')
    {
        // Convert different units to standard base unit for comparison
        $conversionFactors = [
            'kg' => 1,
            'tonnes' => 1000,
            'bags' => 90, // Assuming standard 90kg bag
            'sacks' => 90,
            'crates' => 20, // Assuming 20kg crate
            'boxes' => 10,  // Assuming 10kg box
        ];

        $factor = $conversionFactors[$this->unit] ?? 1;
        $quantityInBaseUnit = $this->quantity_sold * $factor;
        
        return $quantityInBaseUnit > 0 ? $this->gross_income / $quantityInBaseUnit : 0;
    }

    // Static utility methods
    public static function getBuyerTypeOptions()
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::BUYER_TYPES), self::BUYER_TYPES);
    }

    public static function getQualityGradeOptions()
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::QUALITY_GRADES), self::QUALITY_GRADES);
    }

    public static function getUnitOptions()
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::UNITS), self::UNITS);
    }

    public static function getTotalSalesByMonth($farmId, $months = 12)
    {
        return self::where('farm_id', $farmId)
                  ->where('sale_date', '>=', now()->subMonths($months))
                  ->selectRaw('YEAR(sale_date) as year, MONTH(sale_date) as month, SUM(total_amount) as total, SUM(quantity_sold) as quantity')
                  ->groupByRaw('YEAR(sale_date), MONTH(sale_date)')
                  ->orderByRaw('year, month')
                  ->get()
                  ->map(function($item) {
                      return [
                          'period' => $item->year . '-' . str_pad($item->month, 2, '0', STR_PAD_LEFT),
                          'total_revenue' => $item->total,
                          'total_quantity' => $item->quantity
                      ];
                  });
    }

    public static function getSalesByBuyerType($farmId, $startDate = null, $endDate = null)
    {
        $query = self::where('farm_id', $farmId);
        
        if ($startDate && $endDate) {
            $query->whereBetween('sale_date', [$startDate, $endDate]);
        }

        return $query->selectRaw('buyer_type, SUM(total_amount) as total_revenue, SUM(quantity_sold) as total_quantity, COUNT(*) as transaction_count')
                    ->groupBy('buyer_type')
                    ->get()
                    ->mapWithKeys(function($item) {
                        return [$item->buyer_type => [
                            'total_revenue' => $item->total_revenue,
                            'total_quantity' => $item->total_quantity,
                            'transaction_count' => $item->transaction_count,
                            'average_price' => $item->total_quantity > 0 ? $item->total_revenue / $item->total_quantity : 0
                        ]];
                    });
    }

    /**
     * Get comprehensive sales summary for a given period with filters
     */
    public static function getSalesSummary(Carbon $startDate, Carbon $endDate, array $filters = []): array
    {
        $query = self::approved()->byDateRange($startDate, $endDate);

        // Apply filters
        if (!empty($filters['buyer_id'])) {
            $query->where('buyer_id', $filters['buyer_id']);
        }
        
        if (!empty($filters['sale_type'])) {
            $query->where('sale_type', $filters['sale_type']);
        }
        
        if (!empty($filters['payment_status'])) {
            $query->where('payment_status', $filters['payment_status']);
        }

        $sales = $query->with(['buyer', 'deductions'])->get();

        // Calculate totals
        $totalSales = $sales->count();
        $totalQuantity = $sales->sum('quantity_sold');
        $totalRevenue = $sales->sum('gross_income');
        $totalDeductions = $sales->sum('total_deductions');
        $netIncome = $sales->sum('net_income');
        $averagePricePerUnit = $totalQuantity > 0 ? $totalRevenue / $totalQuantity : 0;

        // Group by sale type
        $bySaleType = $sales->groupBy('sale_type')->map(function ($groupSales, $saleType) use ($totalRevenue) {
            $count = $groupSales->count();
            $quantity = $groupSales->sum('quantity_sold');
            $revenue = $groupSales->sum('gross_income');
            
            return [
                'sale_type' => $saleType,
                'count' => $count,
                'total_quantity' => $quantity,
                'total_revenue' => $revenue,
                'percentage_of_total' => $totalRevenue > 0 ? ($revenue / $totalRevenue) * 100 : 0
            ];
        })->values()->toArray();

        // Group by payment status
        $byPaymentStatus = $sales->groupBy('payment_status')->map(function ($groupSales, $paymentStatus) use ($netIncome) {
            $count = $groupSales->count();
            $amount = $groupSales->sum('net_income');
            
            return [
                'payment_status' => $paymentStatus,
                'count' => $count,
                'total_amount' => $amount,
                'percentage_of_total' => $netIncome > 0 ? ($amount / $netIncome) * 100 : 0
            ];
        })->values()->toArray();

        // Buyer insights
        $uniqueBuyerIds = $sales->pluck('buyer_id')->filter()->unique();
        $totalBuyers = $uniqueBuyerIds->count();
        
        // Simplified new vs returning buyers logic (could be enhanced with date-based tracking)
        $newBuyers = $totalBuyers; // All buyers in period for now
        $returningBuyers = 0;
        
        $topBuyer = $sales->groupBy('buyer_name')->map(function ($buyerSales) {
            return [
                'name' => $buyerSales->first()->buyer_name,
                'total_purchases' => $buyerSales->sum('net_income')
            ];
        })->sortByDesc('total_purchases')->first();

        // Deduction summary
        $allDeductions = $sales->flatMap->deductions;
        $deductionsByType = $allDeductions->groupBy('deduction_type')->map(function ($typeDeductions, $type) use ($totalDeductions) {
            $amount = $typeDeductions->sum('amount');
            return [
                'type' => $type,
                'total_amount' => $amount,
                'percentage' => $totalDeductions > 0 ? ($amount / $totalDeductions) * 100 : 0
            ];
        })->values()->toArray();

        return [
            'period' => [
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'days' => $startDate->diffInDays($endDate) + 1
            ],
            'totals' => [
                'total_sales' => $totalSales,
                'total_quantity' => $totalQuantity,
                'total_revenue' => $totalRevenue,
                'total_deductions' => $totalDeductions,
                'net_income' => $netIncome,
                'average_price_per_unit' => $averagePricePerUnit
            ],
            'by_sale_type' => $bySaleType,
            'by_payment_status' => $byPaymentStatus,
            'buyer_insights' => [
                'total_buyers' => $totalBuyers,
                'new_buyers' => $newBuyers,
                'returning_buyers' => $returningBuyers,
                'top_buyer' => $topBuyer ?: ['name' => 'None', 'total_purchases' => 0]
            ],
            'deduction_summary' => [
                'total_deductions' => $totalDeductions,
                'deduction_percentage' => $totalRevenue > 0 ? ($totalDeductions / $totalRevenue) * 100 : 0,
                'by_type' => $deductionsByType
            ],
            'filters' => $filters
        ];
    }
}
