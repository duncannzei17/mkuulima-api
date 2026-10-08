<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Carbon\Carbon;

class Transaction extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'transaction_reference', 'type', 'category', 'amount', 'payment_method',
        'description', 'transaction_date', 'linked_id', 'linked_type', 'status',
        'created_by_user_id', 'approved_by_user_id', 'approved_at', 'approval_notes',
        'mpesa_transaction_id', 'mpesa_receipt_number', 'phone_number', 'mpesa_type',
        'fees', 'net_amount', 'currency', 'exchange_rate', 'is_reconciled',
        'reconciled_at', 'reconciled_by_user_id', 'reconciliation_notes',
        'is_voided', 'voided_at', 'voided_by_user_id', 'void_reason', 'metadata'
    ];

    protected $casts = [
        'id' => 'string',
        'amount' => 'decimal:2',
        'fees' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'transaction_date' => 'datetime',
        'approved_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'voided_at' => 'datetime',
        'is_reconciled' => 'boolean',
        'is_voided' => 'boolean',
        'metadata' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
            if (empty($model->transaction_reference)) {
                $model->transaction_reference = static::generateTransactionReference();
            }
            if (empty($model->net_amount)) {
                $model->net_amount = $model->amount - $model->fees;
            }
        });
    }

    // Relationships
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function linkedRecord(): MorphTo
    {
        return $this->morphTo('linked', 'linked_type', 'linked_id');
    }

    public function mpesaLog(): HasOne
    {
        return $this->hasOne(MpesaLog::class);
    }

    // Scopes
    public function scopeIncome($query)
    {
        return $query->where('type', 'income');
    }

    public function scopeExpense($query)
    {
        return $query->where('type', 'expense');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['draft', 'pending_approval', 'approved', 'processing']);
    }

    public function scopeMpesa($query)
    {
        return $query->where('payment_method', 'mpesa');
    }

    public function scopeCash($query)
    {
        return $query->where('payment_method', 'cash');
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('transaction_date', [$startDate, $endDate]);
    }

    public function scopeReconciled($query)
    {
        return $query->where('is_reconciled', true);
    }

    public function scopeUnreconciled($query)
    {
        return $query->where('is_reconciled', false);
    }

    // Business Logic Methods
    public static function generateTransactionReference(): string
    {
        $prefix = 'TXN';
        $timestamp = now()->format('Ymd');
        $random = strtoupper(Str::random(6));
        return "{$prefix}-{$timestamp}-{$random}";
    }

    public function canBeApproved(): bool
    {
        return in_array($this->status, ['draft', 'pending_approval']);
    }

    public function canBeVoided(): bool
    {
        return !$this->is_voided && in_array($this->status, ['completed', 'failed']);
    }

    public function approve(User $approver, ?string $notes = null): bool
    {
        if (!$this->canBeApproved()) {
            return false;
        }

        $this->status = 'approved';
        $this->approved_by_user_id = $approver->id;
        $this->approved_at = now();
        $this->approval_notes = $notes;

        return $this->save();
    }

    public function void(User $voider, string $reason): bool
    {
        if (!$this->canBeVoided()) {
            return false;
        }

        $wasCompleted = $this->status === 'completed';
        $this->is_voided = true;
        $this->voided_by_user_id = $voider->id;
        $this->voided_at = now();
        $this->void_reason = $reason;
        $this->status = 'cancelled';

        // Update wallet balance if this was completed
        if ($wasCompleted) {
            $this->reverseWalletImpact();
        }

        return $this->save();
    }

    public function reconcile(User $reconciler, ?string $notes = null): bool
    {
        if ($this->is_reconciled) {
            return false;
        }

        $this->is_reconciled = true;
        $this->reconciled_by_user_id = $reconciler->id;
        $this->reconciled_at = now();
        $this->reconciliation_notes = $notes;

        return $this->save();
    }

    public function markCompleted(): bool
    {
        if ($this->status !== 'processing') {
            return false;
        }

        $this->status = 'completed';
        $this->updateWalletBalance();
        $this->updateLinkedRecord();

        return $this->save();
    }

    public function markFailed(string $reason): bool
    {
        $this->status = 'failed';
        $this->metadata = array_merge($this->metadata ?? [], [
            'failure_reason' => $reason,
            'failed_at' => now()->toISOString()
        ]);

        return $this->save();
    }

    protected function updateWalletBalance(): void
    {
        $wallet = FarmWallet::where('farm_id', request()->header('X-Tenant-ID'))->first();
        if (!$wallet) return;

        $impact = $this->type === 'income' ? $this->net_amount : -$this->net_amount;

        switch ($this->payment_method) {
            case 'cash':
                $wallet->cash_balance += $impact;
                break;
            case 'mpesa':
                $wallet->mpesa_balance += $impact;
                break;
            case 'bank_transfer':
                $wallet->bank_balance += $impact;
                break;
        }

        $wallet->total_balance = $wallet->cash_balance + $wallet->mpesa_balance + $wallet->bank_balance;
        $wallet->last_balance_update = now();
        $wallet->last_updated_by_user_id = $this->created_by_user_id;

        // Update monthly aggregates
        if ($wallet->current_month !== now()->format('Y-m-01')) {
            $wallet->resetMonthlyAggregates();
        }

        if ($this->type === 'income') {
            $wallet->monthly_income += $this->net_amount;
        } else {
            $wallet->monthly_expenses += $this->net_amount;
        }
        $wallet->monthly_net_flow = $wallet->monthly_income - $wallet->monthly_expenses;

        $wallet->save();
    }

    protected function reverseWalletImpact(): void
    {
        $wallet = FarmWallet::where('farm_id', request()->header('X-Tenant-ID'))->first();
        if (!$wallet) return;

        $impact = $this->type === 'income' ? -$this->net_amount : $this->net_amount;

        switch ($this->payment_method) {
            case 'cash':
                $wallet->cash_balance += $impact;
                break;
            case 'mpesa':
                $wallet->mpesa_balance += $impact;
                break;
            case 'bank_transfer':
                $wallet->bank_balance += $impact;
                break;
        }

        $wallet->total_balance = $wallet->cash_balance + $wallet->mpesa_balance + $wallet->bank_balance;
        $wallet->save();
    }

    protected function updateLinkedRecord(): void
    {
        if (!$this->linked_id || !$this->linked_type) return;

        switch ($this->linked_type) {
            case 'labour':
                $labour = Labour::find($this->linked_id);
                if ($labour && $this->type === 'expense') {
                    $labour->payment_status = 'paid';
                    $labour->paid_amount = $this->amount;
                    $labour->paid_at = now();
                    $labour->save();
                }
                break;

            case 'expense':
                $expense = Expense::find($this->linked_id);
                if ($expense && $this->type === 'expense') {
                    $expense->payment_status = 'paid';
                    $expense->paid_amount = $this->amount;
                    $expense->paid_at = now();
                    $expense->save();
                }
                break;

            case 'sale':
                $sale = Sale::find($this->linked_id);
                if ($sale && $this->type === 'income') {
                    $sale->payment_status = 'paid';
                    $sale->paid_amount += $this->amount;
                    if ($sale->paid_amount >= $sale->total_amount) {
                        $sale->payment_status = 'fully_paid';
                    }
                    $sale->save();
                }
                break;
        }
    }

    // Analytics and Reporting
    public static function getCashflowSummary(Carbon $startDate, Carbon $endDate): array
    {
        $transactions = static::byDateRange($startDate, $endDate)->completed()->get();

        $income = $transactions->where('type', 'income')->sum('net_amount');
        $expenses = $transactions->where('type', 'expense')->sum('net_amount');
        
        return [
            'total_income' => $income,
            'total_expenses' => $expenses,
            'net_cashflow' => $income - $expenses,
            'transaction_count' => $transactions->count(),
            'average_transaction' => $transactions->count() > 0 ? $transactions->avg('amount') : 0,
            'income_by_method' => $transactions->where('type', 'income')->groupBy('payment_method')
                ->map(fn($group) => $group->sum('net_amount')),
            'expenses_by_category' => $transactions->where('type', 'expense')->groupBy('category')
                ->map(fn($group) => $group->sum('net_amount')),
        ];
    }

    public static function getOutstandingPayments(): array
    {
        return [
            'unpaid_labour' => Labour::where('payment_status', 'unpaid')->sum('amount_due'),
            'unpaid_expenses' => Expense::where('payment_status', 'unpaid')->sum('amount'),
            'pending_payments' => PendingPayment::whereIn('status', ['approved', 'processing'])->sum('amount'),
            'count_labour' => Labour::where('payment_status', 'unpaid')->count(),
            'count_expenses' => Expense::where('payment_status', 'unpaid')->count(),
            'count_pending' => PendingPayment::whereIn('status', ['approved', 'processing'])->count(),
        ];
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['draft', 'pending_approval', 'approved', 'processing']);
    }

    public function requiresApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'completed' => 'success',
            'failed', 'cancelled' => 'danger',
            'pending_approval', 'processing' => 'warning',
            'approved' => 'info',
            default => 'secondary'
        };
    }
}
