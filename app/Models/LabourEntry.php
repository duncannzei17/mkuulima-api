<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class LabourEntry extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $appends = [
        'total_amount',
    ];

    protected $fillable = [
        'worker_id',
        'worker_name',
        'labour_date',
        'labour_type',
        'payment_type',
        'amount',
        'units',
        'number_of_workers',
        'crop_cycle_id',
        'bed_id',
        'description',
        'status',
        'payment_status',
        'payment_date',
        'payment_method',
        'paid_amount',
        'payment_reference',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'id' => 'string',
        'worker_id' => 'string',
        'crop_cycle_id' => 'string',
        'bed_id' => 'string',
        'created_by' => 'string',
        'approved_by' => 'string',
        'labour_date' => 'date',
        'payment_date' => 'date',
        'approved_at' => 'datetime',
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'units' => 'integer',
        'number_of_workers' => 'integer',
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

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(LabourApproval::class);
    }

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

    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('payment_status', ['unpaid', 'partial']);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('labour_date', [$startDate, $endDate]);
    }

    public function scopeByLabourType($query, $type)
    {
        return $query->where('labour_type', $type);
    }

    public function scopeByWorker($query, $workerId)
    {
        return $query->where('worker_id', $workerId);
    }

    public function scopeByCropCycle($query, $cropCycleId)
    {
        return $query->where('crop_cycle_id', $cropCycleId);
    }

    public function approve($approverId = null, $reason = null)
    {
        if ($this->status !== 'pending') {
            throw new \Exception('Only pending labour entries can be approved');
        }

        DB::transaction(function () use ($approverId, $reason) {
            $this->status = 'approved';
            $this->approved_by = $approverId ?: auth()->id();
            $this->approved_at = now();
            $this->save();

            $this->approvals()->create([
                'action' => 'approved',
                'reason' => $reason,
                'approved_by' => $this->approved_by,
            ]);

            $this->createExpenseEntry();
        });
    }

    public function reject($approverId = null, $reason = null)
    {
        if ($this->status !== 'pending') {
            throw new \Exception('Only pending labour entries can be rejected');
        }

        $this->status = 'rejected';
        $this->approved_by = $approverId ?: auth()->id();
        $this->approved_at = now();
        $this->save();

        $this->approvals()->create([
            'action' => 'rejected',
            'reason' => $reason,
            'approved_by' => $this->approved_by,
        ]);
    }

    public function recordPayment($paidAmount, $paymentMethod = 'cash', $paymentDate = null, $paymentReference = null)
    {
        if ($this->status !== 'approved') {
            throw new \Exception('Only approved labour entries can be marked as paid');
        }

        $total = (float) $this->total_amount;
        $amount = (float) $paidAmount;

        if ($amount <= 0 || $amount > $total) {
            throw new \Exception('Paid amount must be greater than zero and cannot exceed the labour total');
        }

        DB::transaction(function () use ($amount, $total, $paymentMethod, $paymentDate, $paymentReference) {
            $this->paid_amount = $amount;
            $this->payment_status = $amount < $total ? 'partial' : 'paid';
            $this->payment_method = $paymentMethod;
            $this->payment_date = $paymentDate ?: now()->toDateString();
            $this->payment_reference = $paymentReference;
            $this->save();

            Expense::where('metadata->labour_entry_id', $this->id)->update([
                'payment_method' => $paymentMethod === 'mpesa' ? 'm_pesa' : $paymentMethod,
                'payment_reference' => $paymentReference,
            ]);
        });
    }

    public function getTotalAmountAttribute()
    {
        if ($this->payment_type === 'piece_rate' && $this->units) {
            return (float) $this->amount * $this->units;
        }

        return (float) $this->amount;
    }

    public function getPerWorkerAmountAttribute()
    {
        if ($this->payment_type === 'group_labour' && $this->number_of_workers > 1) {
            return $this->total_amount / $this->number_of_workers;
        }

        return $this->total_amount;
    }

    public function getDaysOverdueAttribute()
    {
        if ($this->payment_status === 'paid') {
            return 0;
        }

        $dueDate = Carbon::parse($this->labour_date)->addDays(7);
        return max(0, now()->diffInDays($dueDate, false));
    }

    public function getIsOverdueAttribute()
    {
        return $this->days_overdue > 0;
    }

    protected function createExpenseEntry()
    {
        if ($this->status === 'approved') {
            $labourCategory = ExpenseCategory::firstOrCreate(
                ['slug' => 'labour'],
                [
                    'name' => 'Labour',
                    'description' => 'Farm worker wages and labour payments',
                    'color' => '#10B981',
                    'icon' => 'user-group',
                    'is_system_default' => true,
                    'created_by' => $this->approved_by ?? $this->created_by,
                ]
            );

            Expense::firstOrCreate(
                ['metadata->labour_entry_id' => $this->id],
                [
                    'category_id' => $labourCategory->id,
                    'amount' => $this->total_amount,
                    'description' => "Labour: {$this->labour_type} by {$this->worker_name}",
                    'expense_date' => $this->labour_date,
                    'payment_method' => $this->payment_method ?? 'cash',
                    'status' => 'approved',
                    'approved_by' => $this->approved_by,
                    'approved_at' => $this->approved_at,
                    'created_by' => $this->created_by,
                    'crop_cycle_id' => $this->crop_cycle_id,
                    'bed_id' => $this->bed_id,
                    'metadata' => ['labour_entry_id' => $this->id],
                ]
            );
        }
    }

    public static function getLabourSummary($startDate = null, $endDate = null)
    {
        $query = self::approved();

        if ($startDate) {
            $query->where('labour_date', '>=', $startDate);
        }

        if ($endDate) {
            $query->where('labour_date', '<=', $endDate);
        }

        $entries = $query->get();

        return [
            'total_cost' => $entries->sum('total_amount'),
            'total_entries' => $entries->count(),
            'by_type' => $entries->groupBy('labour_type')->map(function($group) {
                return [
                    'count' => $group->count(),
                    'total_cost' => $group->sum('total_amount'),
                ];
            }),
            'by_payment_status' => [
                'paid' => $entries->where('payment_status', 'paid')->sum('total_amount'),
                'unpaid' => $entries->where('payment_status', 'unpaid')->sum('total_amount'),
                'partial' => $entries->where('payment_status', 'partial')->sum('total_amount'),
            ],
            'pending_approvals' => self::pending()->count(),
            'overdue_payments' => $entries->filter->is_overdue->count(),
        ];
    }

    public static function getDailyLabourCost($date)
    {
        return self::approved()
                  ->where('labour_date', $date)
                  ->sum('total_amount');
    }

    public static function getMostExpensiveLabourTypes($limit = 5)
    {
        return self::approved()
                  ->selectRaw('labour_type, SUM(amount) as total_cost, COUNT(*) as entry_count')
                  ->groupBy('labour_type')
                  ->orderBy('total_cost', 'desc')
                  ->limit($limit)
                  ->get();
    }
}
