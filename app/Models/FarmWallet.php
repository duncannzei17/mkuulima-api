<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Carbon\Carbon;

class FarmWallet extends Model
{
    use HasFactory;

    protected $table = 'farm_wallet';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'farm_id', 'cash_balance', 'mpesa_balance', 'bank_balance', 'total_balance',
        'currency', 'opening_balance', 'closing_balance_previous', 'last_balance_update',
        'last_updated_by_user_id', 'monthly_income', 'monthly_expenses', 'monthly_net_flow',
        'current_month', 'is_reconciled', 'last_reconciled_at', 'reconciled_by_user_id',
        'reconciliation_difference', 'reconciliation_notes', 'daily_spending_limit',
        'weekly_spending_limit', 'monthly_spending_limit', 'current_daily_spending',
        'current_weekly_spending', 'current_monthly_spending', 'low_balance_alert_threshold',
        'negative_balance_alert_threshold', 'alerts_enabled', 'last_alert_sent_at',
        'average_daily_income', 'average_daily_expense', 'cash_flow_variance',
        'days_with_positive_flow', 'days_with_negative_flow', 'mpesa_business_shortcode',
        'mpesa_till_number', 'bank_account_details', 'mobile_money_accounts',
        'balance_change_log', 'is_locked', 'locked_at', 'locked_by_user_id',
        'lock_reason', 'daily_snapshots', 'last_backup_at', 'backup_metadata'
    ];

    protected $casts = [
        'id' => 'string',
        'cash_balance' => 'decimal:2',
        'mpesa_balance' => 'decimal:2',
        'bank_balance' => 'decimal:2',
        'total_balance' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'closing_balance_previous' => 'decimal:2',
        'monthly_income' => 'decimal:2',
        'monthly_expenses' => 'decimal:2',
        'monthly_net_flow' => 'decimal:2',
        'reconciliation_difference' => 'decimal:2',
        'daily_spending_limit' => 'decimal:2',
        'weekly_spending_limit' => 'decimal:2',
        'monthly_spending_limit' => 'decimal:2',
        'current_daily_spending' => 'decimal:2',
        'current_weekly_spending' => 'decimal:2',
        'current_monthly_spending' => 'decimal:2',
        'low_balance_alert_threshold' => 'decimal:2',
        'negative_balance_alert_threshold' => 'decimal:2',
        'average_daily_income' => 'decimal:2',
        'average_daily_expense' => 'decimal:2',
        'cash_flow_variance' => 'decimal:2',
        'current_month' => 'date',
        'last_balance_update' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'last_alert_sent_at' => 'datetime',
        'locked_at' => 'datetime',
        'last_backup_at' => 'datetime',
        'is_reconciled' => 'boolean',
        'alerts_enabled' => 'boolean',
        'is_locked' => 'boolean',
        'bank_account_details' => 'array',
        'mobile_money_accounts' => 'array',
        'balance_change_log' => 'array',
        'daily_snapshots' => 'array',
        'backup_metadata' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
            if (empty($model->current_month)) {
                $model->current_month = now()->format('Y-m-01');
            }
        });

        static::updating(function ($model) {
            // Log significant balance changes
            if ($model->isDirty('total_balance')) {
                $change = $model->total_balance - $model->getOriginal('total_balance');
                if (abs($change) > 1000) { // Log changes over 1000
                    $log = $model->balance_change_log ?? [];
                    $log[] = [
                        'amount' => $change,
                        'old_balance' => $model->getOriginal('total_balance'),
                        'new_balance' => $model->total_balance,
                        'changed_at' => now()->toISOString(),
                        'changed_by' => auth()->id(),
                        'source' => 'wallet_update'
                    ];
                    $model->balance_change_log = array_slice($log, -50); // Keep last 50 changes
                }
            }
        });
    }

    // Relationships
    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function lastUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_updated_by_user_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by_user_id');
    }

    // Scopes
    public function scopeLowBalance($query)
    {
        return $query->whereRaw('total_balance <= low_balance_alert_threshold');
    }

    public function scopeNegativeBalance($query)
    {
        return $query->where('total_balance', '<', 0);
    }

    public function scopeUnreconciled($query)
    {
        return $query->where('is_reconciled', false);
    }

    public function scopeLocked($query)
    {
        return $query->where('is_locked', true);
    }

    public function scopeAlertsEnabled($query)
    {
        return $query->where('alerts_enabled', true);
    }

    // Balance Management Methods
    public function updateBalance(float $amount, string $method, string $type = 'income'): bool
    {
        if ($this->is_locked) {
            throw new \Exception('Wallet is locked and cannot be modified');
        }

        $change = $type === 'income' ? $amount : -$amount;

        // Update specific balance
        switch ($method) {
            case 'cash':
                $this->cash_balance += $change;
                break;
            case 'mpesa':
                $this->mpesa_balance += $change;
                break;
            case 'bank_transfer':
                $this->bank_balance += $change;
                break;
            default:
                throw new \InvalidArgumentException("Invalid payment method: {$method}");
        }

        // Update total balance
        $this->total_balance = $this->cash_balance + $this->mpesa_balance + $this->bank_balance;
        $this->last_balance_update = now();
        $this->last_updated_by_user_id = auth()->id();

        // Update monthly aggregates if needed
        $this->updateMonthlyAggregates($amount, $type);
        
        // Update daily spending limits
        $this->updateSpendingLimits($amount, $type);

        // Check for alerts
        $this->checkAndSendAlerts();

        return $this->save();
    }

    public function reconcile(array $actualBalances, User $reconciler, ?string $notes = null): array
    {
        $differences = [];

        foreach ($actualBalances as $method => $actualBalance) {
            $systemBalance = $this->{$method . '_balance'} ?? 0;
            $difference = $actualBalance - $systemBalance;
            
            if (abs($difference) > 0.01) { // Allow for minor rounding differences
                $differences[$method] = [
                    'system' => $systemBalance,
                    'actual' => $actualBalance,
                    'difference' => $difference
                ];
            }
        }

        if (!empty($differences)) {
            // Update balances to match actual
            foreach ($differences as $method => $diff) {
                $this->{$method . '_balance'} = $diff['actual'];
            }
            
            $this->total_balance = $this->cash_balance + $this->mpesa_balance + $this->bank_balance;
            $this->reconciliation_difference = array_sum(array_column($differences, 'difference'));
        } else {
            $this->reconciliation_difference = 0;
        }

        $this->is_reconciled = empty($differences);
        $this->last_reconciled_at = now();
        $this->reconciled_by_user_id = $reconciler->id;
        $this->reconciliation_notes = $notes;

        $this->save();

        return $differences;
    }

    public function lock(User $locker, string $reason): bool
    {
        $this->is_locked = true;
        $this->locked_at = now();
        $this->locked_by_user_id = $locker->id;
        $this->lock_reason = $reason;

        return $this->save();
    }

    public function unlock(): bool
    {
        $this->is_locked = false;
        $this->locked_at = null;
        $this->locked_by_user_id = null;
        $this->lock_reason = null;

        return $this->save();
    }

    protected function updateMonthlyAggregates(float $amount, string $type): void
    {
        $currentMonth = now()->format('Y-m-01');
        
        // Reset monthly aggregates if we're in a new month
        if ($this->current_month !== $currentMonth) {
            $this->resetMonthlyAggregates();
        }

        if ($type === 'income') {
            $this->monthly_income += $amount;
        } else {
            $this->monthly_expenses += $amount;
        }

        $this->monthly_net_flow = $this->monthly_income - $this->monthly_expenses;
    }

    public function resetMonthlyAggregates(): void
    {
        $this->closing_balance_previous = $this->total_balance;
        $this->monthly_income = 0;
        $this->monthly_expenses = 0;
        $this->monthly_net_flow = 0;
        $this->current_month = now()->format('Y-m-01');
        $this->current_daily_spending = 0;
        $this->current_weekly_spending = 0;
        $this->current_monthly_spending = 0;
    }

    protected function updateSpendingLimits(float $amount, string $type): void
    {
        if ($type === 'expense') {
            $this->current_daily_spending += $amount;
            $this->current_weekly_spending += $amount;
            $this->current_monthly_spending += $amount;
        }
    }

    protected function checkAndSendAlerts(): void
    {
        if (!$this->alerts_enabled) {
            return;
        }

        $alertsToSend = [];

        // Low balance alert
        if ($this->total_balance <= $this->low_balance_alert_threshold) {
            $alertsToSend[] = 'low_balance';
        }

        // Negative balance alert
        if ($this->total_balance <= $this->negative_balance_alert_threshold) {
            $alertsToSend[] = 'negative_balance';
        }

        // Spending limit alerts
        if ($this->daily_spending_limit && $this->current_daily_spending > $this->daily_spending_limit) {
            $alertsToSend[] = 'daily_limit_exceeded';
        }

        if (!empty($alertsToSend)) {
            $this->sendAlerts($alertsToSend);
        }
    }

    protected function sendAlerts(array $alertTypes): void
    {
        // Prevent spam - only send alerts once per hour
        if ($this->last_alert_sent_at && $this->last_alert_sent_at->diffInHours(now()) < 1) {
            return;
        }

        // TODO: Implement actual alert sending (email, SMS, push notifications)
        // For now, just log the alert
        \Log::warning('Farm wallet alerts', [
            'farm_id' => $this->farm_id,
            'alerts' => $alertTypes,
            'current_balance' => $this->total_balance,
            'alert_thresholds' => [
                'low_balance' => $this->low_balance_alert_threshold,
                'negative_balance' => $this->negative_balance_alert_threshold,
            ]
        ]);

        $this->last_alert_sent_at = now();
    }

    // Analytics and Reporting Methods
    public function getDailySnapshot(): array
    {
        return [
            'date' => now()->toDateString(),
            'cash_balance' => $this->cash_balance,
            'mpesa_balance' => $this->mpesa_balance,
            'bank_balance' => $this->bank_balance,
            'total_balance' => $this->total_balance,
            'daily_income' => $this->getDailyIncome(),
            'daily_expenses' => $this->getDailyExpenses(),
            'daily_net_flow' => $this->getDailyNetFlow(),
        ];
    }

    public function storeDailySnapshot(): void
    {
        $snapshots = $this->daily_snapshots ?? [];
        $today = now()->toDateString();
        
        $snapshots[$today] = $this->getDailySnapshot();
        
        // Keep only last 30 days
        $snapshots = array_slice($snapshots, -30, 30, true);
        
        $this->daily_snapshots = $snapshots;
        $this->save();
    }

    public function getDailyIncome(): float
    {
        return Transaction::where('type', 'income')
                         ->where('status', 'completed')
                         ->whereDate('transaction_date', today())
                         ->sum('net_amount');
    }

    public function getDailyExpenses(): float
    {
        return Transaction::where('type', 'expense')
                         ->where('status', 'completed')
                         ->whereDate('transaction_date', today())
                         ->sum('net_amount');
    }

    public function getDailyNetFlow(): float
    {
        return $this->getDailyIncome() - $this->getDailyExpenses();
    }

    public function getCashflowTrend(int $days = 30): array
    {
        $endDate = now();
        $startDate = $endDate->copy()->subDays($days);

        $dailyFlows = Transaction::selectRaw('DATE(transaction_date) as date')
                                ->selectRaw('SUM(CASE WHEN type = "income" THEN net_amount ELSE 0 END) as income')
                                ->selectRaw('SUM(CASE WHEN type = "expense" THEN net_amount ELSE 0 END) as expenses')
                                ->selectRaw('SUM(CASE WHEN type = "income" THEN net_amount ELSE -net_amount END) as net_flow')
                                ->where('status', 'completed')
                                ->whereBetween('transaction_date', [$startDate, $endDate])
                                ->groupBy('date')
                                ->orderBy('date')
                                ->get();

        return $dailyFlows->map(function ($flow) {
            return [
                'date' => $flow->date,
                'income' => $flow->income,
                'expenses' => $flow->expenses,
                'net_flow' => $flow->net_flow,
            ];
        })->toArray();
    }

    public function getBalanceDistribution(): array
    {
        return [
            'cash' => [
                'amount' => $this->cash_balance,
                'percentage' => $this->total_balance > 0 ? ($this->cash_balance / $this->total_balance) * 100 : 0
            ],
            'mpesa' => [
                'amount' => $this->mpesa_balance,
                'percentage' => $this->total_balance > 0 ? ($this->mpesa_balance / $this->total_balance) * 100 : 0
            ],
            'bank' => [
                'amount' => $this->bank_balance,
                'percentage' => $this->total_balance > 0 ? ($this->bank_balance / $this->total_balance) * 100 : 0
            ]
        ];
    }

    public function getSpendingAnalysis(): array
    {
        $totalLimit = $this->monthly_spending_limit ?? 0;
        $used = $this->current_monthly_spending;
        $remaining = $totalLimit - $used;

        return [
            'monthly_limit' => $totalLimit,
            'used' => $used,
            'remaining' => max(0, $remaining),
            'percentage_used' => $totalLimit > 0 ? ($used / $totalLimit) * 100 : 0,
            'daily_average' => now()->day > 0 ? $used / now()->day : 0,
            'projected_monthly' => (now()->day > 0 ? $used / now()->day : 0) * now()->daysInMonth,
        ];
    }

    // Status Checks
    public function isHealthy(): bool
    {
        return $this->total_balance > $this->low_balance_alert_threshold &&
               $this->is_reconciled &&
               !$this->is_locked;
    }

    public function needsReconciliation(): bool
    {
        return !$this->is_reconciled || 
               ($this->last_reconciled_at && $this->last_reconciled_at->diffInDays(now()) > 7);
    }

    public function isOverSpendingLimit(): bool
    {
        return ($this->daily_spending_limit && $this->current_daily_spending > $this->daily_spending_limit) ||
               ($this->weekly_spending_limit && $this->current_weekly_spending > $this->weekly_spending_limit) ||
               ($this->monthly_spending_limit && $this->current_monthly_spending > $this->monthly_spending_limit);
    }
}