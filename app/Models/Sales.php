<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sales extends Model
{
    use HasFactory, HasUuids;

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
        'quality_feedback'
    ];

    protected $casts = [
        'sale_date' => 'date',
        'payment_due_date' => 'date',
        'approved_at' => 'timestamp',
        'quantity_sold' => 'decimal:3',
        'price_per_unit' => 'decimal:2',
        'gross_income' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'net_income' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'amount_outstanding' => 'decimal:2',
        'transport_cost' => 'decimal:2',
        'is_recurring_buyer' => 'boolean',
        'delivery_info' => 'array',
        'quality_feedback' => 'array'
    ];

    public const SALE_TYPES = [
        'farmgate' => 'Farm Gate',
        'market' => 'Market',
        'door_to_door' => 'Door to Door',
        'pickup' => 'Pickup',
        'delivery' => 'Delivery',
        'online' => 'Online',
        'bulk_order' => 'Bulk Order',
        'spot_sale' => 'Spot Sale'
    ];

    public const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'm_pesa' => 'M-Pesa',
        'bank_transfer' => 'Bank Transfer',
        'cheque' => 'Cheque',
        'credit' => 'Credit',
        'mobile_money' => 'Mobile Money',
        'crypto' => 'Crypto',
        'other' => 'Other'
    ];

    public const PAYMENT_STATUSES = [
        'paid' => 'Paid',
        'pending' => 'Pending',
        'partial' => 'Partial',
        'overdue' => 'Overdue'
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected'
    ];

    // Relationships
    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function harvest(): BelongsTo
    {
        return $this->belongsTo(Harvest::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(SalesDeduction::class, 'sale_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(SalesApproval::class, 'sale_id');
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

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('sale_date', [$startDate, $endDate]);
    }

    public function scopeByPaymentStatus($query, $status)
    {
        return $query->where('payment_status', $status);
    }

    // Accessors & Mutators
    public function getIsPaidAttribute(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->payment_status === 'overdue' && 
               $this->payment_due_date && 
               $this->payment_due_date->isPast();
    }

    public function getProfitMarginAttribute(): float
    {
        if ($this->gross_income <= 0) {
            return 0;
        }
        return (($this->net_income / $this->gross_income) * 100);
    }

    // Methods
    public function calculateTotalDeductions(): void
    {
        $this->total_deductions = $this->deductions()->sum('amount');
        $this->net_income = $this->gross_income - $this->total_deductions;
        $this->save();
    }

    public function updateOutstandingAmount(): void
    {
        $this->amount_outstanding = max(0, $this->net_income - $this->amount_paid);
        
        if ($this->amount_outstanding <= 0) {
            $this->payment_status = 'paid';
        } elseif ($this->amount_paid > 0) {
            $this->payment_status = 'partial';
        }
        
        $this->save();
    }

    public function markAsPaid(float $amount = null): void
    {
        $amount = $amount ?? $this->net_income;
        $this->amount_paid = $amount;
        $this->payment_status = 'paid';
        $this->amount_outstanding = 0;
        $this->save();
    }

    public function approve(string $userId, string $reason = null): void
    {
        $this->status = 'approved';
        $this->approved_by = $userId;
        $this->approved_at = now();
        $this->save();

        $this->approvals()->create([
            'approved_by' => $userId,
            'action' => 'approved',
            'reason' => $reason,
            'action_taken_at' => now()
        ]);
    }

    public function reject(string $userId, string $reason): void
    {
        $this->status = 'rejected';
        $this->rejection_reason = $reason;
        $this->save();

        $this->approvals()->create([
            'approved_by' => $userId,
            'action' => 'rejected',
            'reason' => $reason,
            'action_taken_at' => now()
        ]);
    }
}