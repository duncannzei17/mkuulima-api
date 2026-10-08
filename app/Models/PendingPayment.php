<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PendingPayment extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'payment_reference', 'payment_type', 'amount', 'currency', 'payment_reason',
        'priority', 'recipient_name', 'recipient_phone', 'recipient_email',
        'preferred_payment_method', 'payment_details', 'linked_id', 'linked_type',
        'linked_metadata', 'status', 'requested_by_user_id', 'requested_at',
        'manager_approved_by_user_id', 'manager_approved_at', 'manager_approval_notes',
        'owner_approved_by_user_id', 'owner_approved_at', 'owner_approval_notes',
        'rejected_by_user_id', 'rejected_at', 'rejection_reason', 'scheduled_payment_date',
        'is_recurring', 'recurring_frequency', 'recurring_count', 'completed_recurrences',
        'transaction_id', 'payment_initiated_at', 'payment_completed_at',
        'payment_failure_reason', 'payment_retry_count', 'last_retry_at',
        'budget_allocated', 'budget_category', 'exceeds_budget', 'budget_override_reason',
        'notification_settings', 'last_reminder_sent_at', 'reminder_count',
        'supporting_documents', 'approval_history', 'internal_notes'
    ];

    protected $casts = [
        'id' => 'string',
        'amount' => 'decimal:2',
        'budget_allocated' => 'decimal:2',
        'payment_details' => 'array',
        'linked_metadata' => 'array',
        'requested_at' => 'datetime',
        'manager_approved_at' => 'datetime',
        'owner_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'scheduled_payment_date' => 'datetime',
        'payment_initiated_at' => 'datetime',
        'payment_completed_at' => 'datetime',
        'last_retry_at' => 'datetime',
        'last_reminder_sent_at' => 'datetime',
        'is_recurring' => 'boolean',
        'exceeds_budget' => 'boolean',
        'notification_settings' => 'array',
        'supporting_documents' => 'array',
        'approval_history' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
            if (empty($model->payment_reference)) {
                $model->payment_reference = static::generatePaymentReference();
            }
            if (empty($model->requested_at)) {
                $model->requested_at = now();
            }
        });

        static::updating(function ($model) {
            // Track approval history
            if ($model->isDirty('status')) {
                $history = $model->approval_history ?? [];
                $history[] = [
                    'status' => $model->status,
                    'previous_status' => $model->getOriginal('status'),
                    'changed_at' => now()->toISOString(),
                    'changed_by' => auth()->id(),
                    'notes' => $model->getApprovalNotes()
                ];
                $model->approval_history = $history;
            }
        });
    }

    // Relationships
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function managerApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_approved_by_user_id');
    }

    public function ownerApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_approved_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function linkedRecord(): MorphTo
    {
        return $this->morphTo('linked', 'linked_type', 'linked_id');
    }

    // Scopes
    public function scopePendingApproval($query)
    {
        return $query->whereIn('status', ['pending_manager_approval', 'pending_owner_approval']);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeDueToday($query)
    {
        return $query->whereDate('scheduled_payment_date', today());
    }

    public function scopeOverdue($query)
    {
        return $query->where('scheduled_payment_date', '<', now())
                    ->whereNotIn('status', ['completed', 'cancelled', 'rejected']);
    }

    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', ['high', 'urgent']);
    }

    public function scopeRecurring($query)
    {
        return $query->where('is_recurring', true);
    }

    public function scopeByPaymentType($query, string $type)
    {
        return $query->where('payment_type', $type);
    }

    public function scopeByRecipient($query, string $phone)
    {
        return $query->where('recipient_phone', $phone);
    }

    // Business Logic Methods
    public static function generatePaymentReference(): string
    {
        $prefix = 'PAY';
        $timestamp = now()->format('Ymd');
        $random = strtoupper(Str::random(6));
        return "{$prefix}-{$timestamp}-{$random}";
    }

    public function canBeApprovedByManager(): bool
    {
        return $this->status === 'pending_manager_approval';
    }

    public function canBeApprovedByOwner(): bool
    {
        return $this->status === 'pending_owner_approval';
    }

    public function canBeRejected(): bool
    {
        return in_array($this->status, ['pending_manager_approval', 'pending_owner_approval']);
    }

    public function canBeExecuted(): bool
    {
        return $this->status === 'approved' && 
               (!$this->scheduled_payment_date || $this->scheduled_payment_date <= now());
    }

    public function requiresManagerApproval(): bool
    {
        // Business logic: amounts over certain threshold need manager approval
        return $this->amount > 5000 || $this->priority === 'urgent';
    }

    public function requiresOwnerApproval(): bool
    {
        // Business logic: amounts over certain threshold or specific types need owner approval
        return $this->amount > 20000 || 
               in_array($this->payment_type, ['supplier_payment', 'contractor_payment']) ||
               $this->exceeds_budget;
    }

    public function approveByManager(User $manager, ?string $notes = null): bool
    {
        if (!$this->canBeApprovedByManager()) {
            return false;
        }

        $this->manager_approved_by_user_id = $manager->id;
        $this->manager_approved_at = now();
        $this->manager_approval_notes = $notes;

        // Determine next status
        if ($this->requiresOwnerApproval()) {
            $this->status = 'pending_owner_approval';
        } else {
            $this->status = 'approved';
        }

        return $this->save();
    }

    public function approveByOwner(User $owner, ?string $notes = null): bool
    {
        if (!$this->canBeApprovedByOwner()) {
            return false;
        }

        $this->owner_approved_by_user_id = $owner->id;
        $this->owner_approved_at = now();
        $this->owner_approval_notes = $notes;
        $this->status = 'approved';

        return $this->save();
    }

    public function reject(User $rejector, string $reason): bool
    {
        if (!$this->canBeRejected()) {
            return false;
        }

        $this->rejected_by_user_id = $rejector->id;
        $this->rejected_at = now();
        $this->rejection_reason = $reason;
        $this->status = 'rejected';

        return $this->save();
    }

    public function executePayment(): ?Transaction
    {
        if (!$this->canBeExecuted()) {
            return null;
        }

        $this->status = 'processing';
        $this->payment_initiated_at = now();
        $this->save();

        // Create transaction record
        $transaction = Transaction::create([
            'type' => 'expense',
            'category' => $this->mapPaymentTypeToCategory(),
            'amount' => $this->amount,
            'payment_method' => $this->preferred_payment_method,
            'description' => $this->payment_reason,
            'phone_number' => $this->recipient_phone,
            'linked_id' => $this->linked_id,
            'linked_type' => $this->linked_type,
            'status' => 'approved',
            'created_by_user_id' => $this->requested_by_user_id,
            'approved_by_user_id' => $this->owner_approved_by_user_id ?? $this->manager_approved_by_user_id,
            'approved_at' => $this->owner_approved_at ?? $this->manager_approved_at,
        ]);

        $this->transaction_id = $transaction->id;
        $this->save();

        return $transaction;
    }

    public function markCompleted(): bool
    {
        $this->status = 'completed';
        $this->payment_completed_at = now();

        // Handle recurring payments
        if ($this->is_recurring && $this->completed_recurrences < ($this->recurring_count ?? PHP_INT_MAX)) {
            $this->completed_recurrences++;
            $this->scheduleNextRecurrence();
        }

        return $this->save();
    }

    public function markFailed(string $reason): bool
    {
        $this->status = 'failed';
        $this->payment_failure_reason = $reason;
        $this->payment_retry_count++;
        $this->last_retry_at = now();

        // Auto-retry logic
        if ($this->payment_retry_count < 3) {
            $this->status = 'approved'; // Will be retried
        }

        return $this->save();
    }

    protected function scheduleNextRecurrence(): void
    {
        if (!$this->is_recurring || !$this->recurring_frequency) {
            return;
        }

        $nextDate = match($this->recurring_frequency) {
            'weekly' => $this->scheduled_payment_date->addWeek(),
            'monthly' => $this->scheduled_payment_date->addMonth(),
            'quarterly' => $this->scheduled_payment_date->addQuarter(),
            default => null
        };

        if ($nextDate) {
            static::create([
                'payment_type' => $this->payment_type,
                'amount' => $this->amount,
                'payment_reason' => $this->payment_reason,
                'recipient_name' => $this->recipient_name,
                'recipient_phone' => $this->recipient_phone,
                'preferred_payment_method' => $this->preferred_payment_method,
                'scheduled_payment_date' => $nextDate,
                'is_recurring' => true,
                'recurring_frequency' => $this->recurring_frequency,
                'recurring_count' => $this->recurring_count,
                'requested_by_user_id' => $this->requested_by_user_id,
                'status' => $this->requiresManagerApproval() ? 'pending_manager_approval' : 'approved',
            ]);
        }
    }

    protected function mapPaymentTypeToCategory(): string
    {
        return match($this->payment_type) {
            'labour_payment' => 'labour_payment',
            'supplier_payment' => 'supplier_payment',
            'expense_reimbursement' => 'miscellaneous',
            'bonus_payment' => 'labour_payment',
            'advance_payment' => 'labour_payment',
            'refund_payment' => 'refund',
            'contractor_payment' => 'supplier_payment',
            'service_payment' => 'utilities',
            default => 'miscellaneous'
        };
    }

    protected function getApprovalNotes(): ?string
    {
        return match($this->status) {
            'pending_owner_approval' => $this->manager_approval_notes,
            'approved' => $this->owner_approval_notes ?? $this->manager_approval_notes,
            'rejected' => $this->rejection_reason,
            default => null
        };
    }

    // Analytics Methods
    public static function getPendingPaymentsSummary(): array
    {
        $pending = static::pendingApproval()->get();
        
        return [
            'total_count' => $pending->count(),
            'total_amount' => $pending->sum('amount'),
            'high_priority_count' => $pending->where('priority', 'high')->count(),
            'urgent_count' => $pending->where('priority', 'urgent')->count(),
            'overdue_count' => static::overdue()->count(),
            'by_type' => $pending->groupBy('payment_type')->map->count(),
            'by_priority' => $pending->groupBy('priority')->map->count(),
        ];
    }

    public static function getPaymentMetrics(Carbon $startDate, Carbon $endDate): array
    {
        $payments = static::whereBetween('created_at', [$startDate, $endDate])->get();

        return [
            'total_requested' => $payments->count(),
            'total_amount_requested' => $payments->sum('amount'),
            'approved_count' => $payments->where('status', 'approved')->count(),
            'rejected_count' => $payments->where('status', 'rejected')->count(),
            'completed_count' => $payments->where('status', 'completed')->count(),
            'approval_rate' => $payments->count() > 0 ? 
                ($payments->whereIn('status', ['approved', 'completed'])->count() / $payments->count()) * 100 : 0,
            'average_approval_time' => $payments->where('status', 'approved')
                ->filter(fn($p) => $p->owner_approved_at && $p->requested_at)
                ->avg(fn($p) => $p->requested_at->diffInHours($p->owner_approved_at)),
            'by_payment_type' => $payments->groupBy('payment_type')->map->count(),
        ];
    }

    public function isOverdue(): bool
    {
        return $this->scheduled_payment_date && 
               $this->scheduled_payment_date < now() &&
               !in_array($this->status, ['completed', 'cancelled', 'rejected']);
    }

    public function getDaysUntilDue(): int
    {
        if (!$this->scheduled_payment_date) {
            return 0;
        }
        
        return now()->diffInDays($this->scheduled_payment_date, false);
    }

    public function getPriorityColor(): string
    {
        return match($this->priority) {
            'urgent' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            'low' => 'secondary',
            default => 'secondary'
        };
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'approved' => 'success',
            'completed' => 'success',
            'rejected' => 'danger',
            'failed' => 'danger',
            'pending_manager_approval', 'pending_owner_approval' => 'warning',
            'processing' => 'info',
            default => 'secondary'
        };
    }
}