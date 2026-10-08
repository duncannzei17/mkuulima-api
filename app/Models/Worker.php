<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Worker extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'phone',
        'id_number',
        'role',
        'status',
        'default_daily_rate',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'id' => 'string',
        'default_daily_rate' => 'decimal:2',
        'created_by' => 'string',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function labourEntries(): HasMany
    {
        return $this->hasMany(LabourEntry::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByRole($query, $role)
    {
        return $query->where('role', $role);
    }

    public function getTotalLabourCostAttribute()
    {
        return $this->labourEntries()
                   ->where('status', 'approved')
                   ->sum('amount');
    }

    public function getTotalLabourDaysAttribute()
    {
        return $this->labourEntries()
                   ->where('status', 'approved')
                   ->whereIn('payment_type', ['daily', 'multi_day'])
                   ->count();
    }

    public function getLastLabourDateAttribute()
    {
        return $this->labourEntries()
                   ->where('status', 'approved')
                   ->max('labour_date');
    }

    public function getUnpaidAmountAttribute()
    {
        return $this->labourEntries()
                   ->where('status', 'approved')
                   ->whereIn('payment_status', ['unpaid', 'partial'])
                   ->sum('amount');
    }

    public function getAverageDailyRateAttribute()
    {
        $entries = $this->labourEntries()
                       ->where('status', 'approved')
                       ->where('payment_type', 'daily')
                       ->get();

        if ($entries->count() === 0) {
            return $this->default_daily_rate ?? 0;
        }

        return $entries->avg('amount');
    }

    public function canBeDeleted(): bool
    {
        return $this->labourEntries()->count() === 0;
    }

    public function activate()
    {
        $this->status = 'active';
        $this->save();
    }

    public function deactivate()
    {
        $this->status = 'inactive';
        $this->save();
    }

    public function updateDefaultRate($rate)
    {
        $this->default_daily_rate = $rate;
        $this->save();
    }

    public function getLabourSummary($startDate = null, $endDate = null)
    {
        $query = $this->labourEntries()->where('status', 'approved');

        if ($startDate) {
            $query->where('labour_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('labour_date', '<=', $endDate);
        }

        $entries = $query->get();

        return [
            'total_amount' => $entries->sum('amount'),
            'total_days' => $entries->count(),
            'labour_types' => $entries->groupBy('labour_type')->map->count(),
            'payment_status' => [
                'paid' => $entries->where('payment_status', 'paid')->sum('amount'),
                'unpaid' => $entries->where('payment_status', 'unpaid')->sum('amount'),
                'partial' => $entries->where('payment_status', 'partial')->sum('amount'),
            ],
        ];
    }

    public static function getMostActiveWorkers($limit = 10)
    {
        return self::active()
                  ->withCount(['labourEntries' => function($query) {
                      $query->where('status', 'approved')
                           ->where('labour_date', '>=', now()->subDays(30));
                  }])
                  ->orderBy('labour_entries_count', 'desc')
                  ->limit($limit)
                  ->get();
    }
}