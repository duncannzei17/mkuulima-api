<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesDeduction extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'sale_id',
        'deduction_type',
        'amount',
        'description',
        'recipient',
        'notes'
    ];

    protected $casts = [
        'amount' => 'decimal:2'
    ];

    public const DEDUCTION_TYPES = [
        'transport_fare' => 'Transport Fare',
        'airtime' => 'Airtime',
        'packaging' => 'Packaging',
        'labour' => 'Labour',
        'handling_fees' => 'Handling Fees',
        'market_fees' => 'Market Fees',
        'commission' => 'Commission',
        'taxes' => 'Taxes',
        'storage' => 'Storage',
        'loading_offloading' => 'Loading/Offloading',
        'weighing' => 'Weighing',
        'grading_sorting' => 'Grading & Sorting',
        'other' => 'Other'
    ];

    // Relationships
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    // Scopes
    public function scopeByType($query, string $type)
    {
        return $query->where('deduction_type', $type);
    }

    public function scopeByRecipient($query, string $recipient)
    {
        return $query->where('recipient', 'LIKE', "%{$recipient}%");
    }

    // Accessors
    public function getDeductionTypeNameAttribute(): string
    {
        return self::DEDUCTION_TYPES[$this->deduction_type] ?? $this->deduction_type;
    }

    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2);
    }

    // Static utility methods
    public static function getDeductionTypeOptions(): array
    {
        return array_map(function($key, $value) {
            return ['value' => $key, 'label' => $value];
        }, array_keys(self::DEDUCTION_TYPES), self::DEDUCTION_TYPES);
    }

    /**
     * Get deduction summary by type for a sale
     */
    public static function getDeductionSummary(string $saleId): array
    {
        return static::where('sale_id', $saleId)
            ->selectRaw('deduction_type, SUM(amount) as total_amount, COUNT(*) as count')
            ->groupBy('deduction_type')
            ->get()
            ->mapWithKeys(function($item) {
                return [$item->deduction_type => [
                    'type_name' => self::DEDUCTION_TYPES[$item->deduction_type] ?? $item->deduction_type,
                    'total_amount' => $item->total_amount,
                    'count' => $item->count,
                    'average_amount' => $item->count > 0 ? $item->total_amount / $item->count : 0
                ]];
            })
            ->toArray();
    }

    /**
     * Get common deduction patterns
     */
    public static function getCommonDeductions(): array
    {
        return static::selectRaw('deduction_type, AVG(amount) as avg_amount, COUNT(*) as frequency')
            ->groupBy('deduction_type')
            ->orderByDesc('frequency')
            ->limit(10)
            ->get()
            ->map(function($item) {
                return [
                    'type' => $item->deduction_type,
                    'type_name' => self::DEDUCTION_TYPES[$item->deduction_type] ?? $item->deduction_type,
                    'average_amount' => round($item->avg_amount, 2),
                    'frequency' => $item->frequency
                ];
            })
            ->toArray();
    }
}