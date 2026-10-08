<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PaymentSchedule extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'schedule_name', 'description', 'schedule_type', 'amount', 'currency',
        'payment_method', 'recipient_name', 'recipient_phone', 'recipient_email',
        'recipient_payment_details', 'frequency', 'frequency_interval',
        'start_date', 'end_date', 'total_occurrences', 'completed_payments',
        'status', 'auto_execute', 'approval_required', 'next_payment_date',
        'last_payment_date', 'last_payment_id', 'monthly_budget_limit',
        'annual_budget_limit', 'current_month_spent', 'current_year_spent',
        'notification_settings', 'remind_days_before', 'last_reminder_sent',
        'max_retry_attempts', 'current_retry_count', 'last_failure_date',
        'last_failure_reason', 'failure_action', 'linked_id', 'linked_type',
        'linked_metadata', 'created_by_user_id', 'last_modified_by_user_id',
        'modification_history'
    ];

    protected $casts = [
        'id' => 'string',
        'amount' => 'decimal:2',
        'monthly_budget_limit' => 'decimal:2',
        'annual_budget_limit' => 'decimal:2',
        'current_month_spent' => 'decimal:2',
        'current_year_spent' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'next_payment_date' => 'datetime',
        'last_payment_date' => 'datetime',
        'last_reminder_sent' => 'datetime',
        'last_failure_date' => 'datetime',
        'auto_execute' => 'boolean',
        'recipient_payment_details' => 'array',
        'notification_settings' => 'array',
        'linked_metadata' => 'array',
        'modification_history' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
            if (empty($model->next_payment_date) && $model->start_date) {
                $model->next_payment_date = $model->start_date;
            }
        });

        static::updating(function ($model) {
            // Track modifications
            if ($model->isDirty(['amount', 'frequency', 'status'])) {
                $history = $model->modification_history ?? [];
                $history[] = [
                    'changed_fields' => array_keys($model->getDirty()),
                    'old_values' => $model->getOriginal(),
                    'new_values' => $model->getAttributes(),
                    'changed_at' => now()->toISOString(),
                    'changed_by' => auth()->id(),
                ];
                $model->modification_history = array_slice($history, -20); // Keep last 20 modifications
                $model->last_modified_by_user_id = auth()->id();
            }
        });
    }

    // Relationships
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function lastModifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_modified_by_user_id');
    }

    public function lastPayment(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'last_payment_id');
    }

    public function linkedRecord(): MorphTo
    {
        return $this->morphTo('linked', 'linked_type', 'linked_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePaused($query)
    {
        return $query->where('status', 'paused');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeAutoExecute($query)
    {
        return $query->where('auto_execute', true);
    }

    public function scopeDueToday($query)
    {
        return $query->whereDate('next_payment_date', today())
                    ->where('status', 'active');
    }

    public function scopeDueThisWeek($query)
    {
        return $query->whereBetween('next_payment_date', [
                    now()->startOfWeek(),
                    now()->endOfWeek()
                ])
                ->where('status', 'active');
    }

    public function scopeOverdue($query)
    {
        return $query->where('next_payment_date', '<', now())
                    ->where('status', 'active');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('schedule_type', $type);
    }

    public function scopeByFrequency($query, string $frequency)
    {
        return $query->where('frequency', $frequency);
    }

    public function scopeRecurring($query)
    {
        return $query->whereNull('end_date')
                    ->orWhere('end_date', '>', now());
    }

    // Business Logic Methods
    public function isDue(): bool
    {
        return $this->status === 'active' && 
               $this->next_payment_date && 
               $this->next_payment_date <= now();
    }

    public function isOverdue(): bool
    {
        return $this->status === 'active' && 
               $this->next_payment_date && 
               $this->next_payment_date < now()->subHours(24); // 24 hour grace period
    }

    public function canExecute(): bool
    {
        return $this->isDue() && 
               !$this->isCompleted() && 
               !$this->exceedsBudget() &&
               $this->current_retry_count < $this->max_retry_attempts;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed' ||
               ($this->total_occurrences && $this->completed_payments >= $this->total_occurrences) ||
               ($this->end_date && $this->end_date < now()->toDateString());
    }

    public function exceedsBudget(): bool
    {
        $currentMonth = now()->format('Y-m');
        $currentYear = now()->year;

        // Check monthly budget
        if ($this->monthly_budget_limit && 
            $this->current_month_spent + $this->amount > $this->monthly_budget_limit) {
            return true;
        }

        // Check annual budget
        if ($this->annual_budget_limit && 
            $this->current_year_spent + $this->amount > $this->annual_budget_limit) {
            return true;
        }

        return false;
    }

    public function calculateNextPaymentDate(): ?Carbon
    {
        if (!$this->next_payment_date || $this->isCompleted()) {
            return null;
        }

        $currentNext = $this->next_payment_date instanceof Carbon 
            ? $this->next_payment_date 
            : Carbon::parse($this->next_payment_date);

        switch ($this->frequency) {
            case 'daily':
                return $currentNext->copy()->addDays($this->frequency_interval);
            case 'weekly':
                return $currentNext->copy()->addWeeks($this->frequency_interval);
            case 'bi_weekly':
                return $currentNext->copy()->addWeeks(2 * $this->frequency_interval);
            case 'monthly':
                return $currentNext->copy()->addMonths($this->frequency_interval);
            case 'quarterly':
                return $currentNext->copy()->addQuarters($this->frequency_interval);
            case 'yearly':
                return $currentNext->copy()->addYears($this->frequency_interval);
            default:
                return null;
        }
    }

    public function executePayment(): ?PendingPayment
    {
        if (!$this->canExecute()) {
            return null;
        }

        // Create pending payment
        $pendingPayment = PendingPayment::create([
            'payment_type' => $this->mapScheduleTypeToPaymentType(),
            'amount' => $this->amount,
            'payment_reason' => "Scheduled payment: {$this->schedule_name}",
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'recipient_email' => $this->recipient_email,
            'preferred_payment_method' => $this->payment_method,
            'payment_details' => $this->recipient_payment_details,
            'linked_id' => $this->id,
            'linked_type' => 'payment_schedule',
            'priority' => $this->determinePriority(),
            'scheduled_payment_date' => now(),
            'status' => $this->auto_execute ? 'approved' : $this->getApprovalStatus(),
            'requested_by_user_id' => $this->created_by_user_id,
        ]);

        // Update schedule
        $this->completed_payments++;
        $this->last_payment_date = now();
        $this->next_payment_date = $this->calculateNextPaymentDate();
        $this->updateBudgetSpending();

        if ($this->isCompleted()) {
            $this->status = 'completed';
        }

        $this->save();

        return $pendingPayment;
    }

    public function markPaymentCompleted(Transaction $transaction): bool
    {
        $this->last_payment_id = $transaction->id;
        $this->last_payment_date = $transaction->transaction_date;
        $this->current_retry_count = 0;
        $this->last_failure_reason = null;

        return $this->save();
    }

    public function markPaymentFailed(string $reason): bool
    {
        $this->current_retry_count++;
        $this->last_failure_date = now();
        $this->last_failure_reason = $reason;

        // Apply failure action
        switch ($this->failure_action) {
            case 'pause':
                if ($this->current_retry_count >= $this->max_retry_attempts) {
                    $this->status = 'paused';
                }
                break;
            case 'skip':
                if ($this->current_retry_count >= $this->max_retry_attempts) {
                    $this->next_payment_date = $this->calculateNextPaymentDate();
                    $this->current_retry_count = 0;
                }
                break;
            case 'manual_review':
                if ($this->current_retry_count >= $this->max_retry_attempts) {
                    $this->status = 'paused';
                    // TODO: Send notification for manual review
                }
                break;
        }

        return $this->save();
    }

    public function pause(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $this->status = 'paused';
        return $this->save();
    }

    public function resume(): bool
    {
        if ($this->status !== 'paused') {
            return false;
        }

        $this->status = 'active';
        $this->current_retry_count = 0;
        $this->last_failure_reason = null;

        return $this->save();
    }

    public function cancel(): bool
    {
        $this->status = 'cancelled';
        return $this->save();
    }

    protected function mapScheduleTypeToPaymentType(): string
    {
        return match($this->schedule_type) {
            'recurring_labour' => 'labour_payment',
            'recurring_supplier' => 'supplier_payment',
            'loan_payment' => 'supplier_payment',
            'rent_payment' => 'expense_reimbursement',
            'subscription' => 'service_payment',
            'maintenance_contract' => 'contractor_payment',
            'insurance_premium' => 'service_payment',
            'tax_payment' => 'expense_reimbursement',
            default => 'service_payment'
        };
    }

    protected function determinePriority(): string
    {
        return match($this->schedule_type) {
            'loan_payment', 'tax_payment' => 'urgent',
            'insurance_premium', 'rent_payment' => 'high',
            'recurring_labour', 'recurring_supplier' => 'medium',
            default => 'medium'
        };
    }

    protected function getApprovalStatus(): string
    {
        return match($this->approval_required) {
            'none' => 'approved',
            'manager' => 'pending_manager_approval',
            'owner' => 'pending_owner_approval',
            default => 'pending_owner_approval'
        };
    }

    protected function updateBudgetSpending(): void
    {
        $currentMonth = now()->format('Y-m');
        $currentYear = now()->year;

        // Reset monthly spending if new month
        if (!str_starts_with($this->updated_at?->format('Y-m') ?? '', $currentMonth)) {
            $this->current_month_spent = 0;
        }

        // Reset yearly spending if new year
        if ($this->updated_at?->year !== $currentYear) {
            $this->current_year_spent = 0;
        }

        $this->current_month_spent += $this->amount;
        $this->current_year_spent += $this->amount;
    }

    public function sendReminder(): bool
    {
        // Prevent spam - don't send reminders more than once per day
        if ($this->last_reminder_sent && 
            $this->last_reminder_sent->diffInHours(now()) < 24) {
            return false;
        }

        // TODO: Implement actual reminder sending (email, SMS, push notifications)
        \Log::info('Payment schedule reminder sent', [
            'schedule_id' => $this->id,
            'schedule_name' => $this->schedule_name,
            'amount' => $this->amount,
            'due_date' => $this->next_payment_date,
            'recipient' => $this->recipient_name
        ]);

        $this->last_reminder_sent = now();
        return $this->save();
    }

    public function shouldSendReminder(): bool
    {
        if (!$this->next_payment_date || $this->status !== 'active') {
            return false;
        }

        $daysUntilDue = now()->diffInDays($this->next_payment_date, false);
        return $daysUntilDue <= $this->remind_days_before && $daysUntilDue >= 0;
    }

    // Analytics Methods
    public static function getScheduleSummary(): array
    {
        $schedules = static::where('status', 'active')->get();

        return [
            'total_active' => $schedules->count(),
            'due_today' => $schedules->filter->isDue()->count(),
            'overdue' => $schedules->filter->isOverdue()->count(),
            'total_monthly_amount' => $schedules->where('frequency', 'monthly')->sum('amount'),
            'total_annual_amount' => $schedules->sum(function ($schedule) {
                return $schedule->getAnnualAmount();
            }),
            'by_type' => $schedules->groupBy('schedule_type')->map->count(),
            'auto_execute_count' => $schedules->where('auto_execute', true)->count(),
        ];
    }

    public function getAnnualAmount(): float
    {
        return match($this->frequency) {
            'daily' => $this->amount * 365 / $this->frequency_interval,
            'weekly' => $this->amount * 52 / $this->frequency_interval,
            'bi_weekly' => $this->amount * 26 / $this->frequency_interval,
            'monthly' => $this->amount * 12 / $this->frequency_interval,
            'quarterly' => $this->amount * 4 / $this->frequency_interval,
            'yearly' => $this->amount / $this->frequency_interval,
            default => 0
        };
    }

    public function getNextPaymentInfo(): array
    {
        return [
            'next_date' => $this->next_payment_date,
            'amount' => $this->amount,
            'days_until_due' => $this->next_payment_date 
                ? now()->diffInDays($this->next_payment_date, false)
                : null,
            'is_overdue' => $this->isOverdue(),
            'can_execute' => $this->canExecute(),
        ];
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'active' => $this->isOverdue() ? 'danger' : 'success',
            'paused' => 'warning',
            'completed' => 'info',
            'cancelled' => 'secondary',
            default => 'secondary'
        };
    }

    public function getBudgetStatus(): array
    {
        return [
            'monthly' => [
                'limit' => $this->monthly_budget_limit,
                'spent' => $this->current_month_spent,
                'remaining' => $this->monthly_budget_limit ? 
                    max(0, $this->monthly_budget_limit - $this->current_month_spent) : null,
                'percentage' => $this->monthly_budget_limit ? 
                    ($this->current_month_spent / $this->monthly_budget_limit) * 100 : 0
            ],
            'annual' => [
                'limit' => $this->annual_budget_limit,
                'spent' => $this->current_year_spent,
                'remaining' => $this->annual_budget_limit ? 
                    max(0, $this->annual_budget_limit - $this->current_year_spent) : null,
                'percentage' => $this->annual_budget_limit ? 
                    ($this->current_year_spent / $this->annual_budget_limit) * 100 : 0
            ]
        ];
    }
}