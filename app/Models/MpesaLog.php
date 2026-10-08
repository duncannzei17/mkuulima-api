<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Carbon\Carbon;

class MpesaLog extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'transaction_id', 'mpesa_transaction_id', 'mpesa_receipt_number',
        'checkout_request_id', 'merchant_request_id', 'transaction_type',
        'flow_direction', 'sender_phone', 'receiver_phone', 'sender_name',
        'receiver_name', 'amount', 'transaction_cost', 'business_balance',
        'currency', 'result_code', 'result_description', 'response_code',
        'response_description', 'request_payload', 'response_payload',
        'callback_payload', 'callback_received_at', 'status', 'failure_reason',
        'retry_count', 'last_retry_at', 'api_endpoint', 'ip_address',
        'user_agent', 'is_duplicate', 'duplicate_of', 'initiated_at',
        'completed_at', 'processing_time_ms', 'is_reconciled', 'reconciled_at',
        'reconciliation_notes', 'created_by_user_id', 'audit_trail'
    ];

    protected $casts = [
        'id' => 'string',
        'amount' => 'decimal:2',
        'transaction_cost' => 'decimal:2',
        'business_balance' => 'decimal:2',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'callback_payload' => 'array',
        'callback_received_at' => 'datetime',
        'last_retry_at' => 'datetime',
        'is_duplicate' => 'boolean',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_reconciled' => 'boolean',
        'reconciled_at' => 'datetime',
        'audit_trail' => 'array'
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid();
            }
            if (empty($model->initiated_at)) {
                $model->initiated_at = now();
            }
        });

        static::updating(function ($model) {
            // Track status changes in audit trail
            if ($model->isDirty('status')) {
                $auditTrail = $model->audit_trail ?? [];
                $auditTrail[] = [
                    'field' => 'status',
                    'old_value' => $model->getOriginal('status'),
                    'new_value' => $model->status,
                    'changed_at' => now()->toISOString(),
                    'changed_by' => auth()->id()
                ];
                $model->audit_trail = $auditTrail;
            }
        });
    }

    // Relationships
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    // Scopes
    public function scopeC2B($query)
    {
        return $query->where('transaction_type', 'C2B');
    }

    public function scopeB2C($query)
    {
        return $query->where('transaction_type', 'B2C');
    }

    public function scopeStkPush($query)
    {
        return $query->where('transaction_type', 'STK_PUSH');
    }

    public function scopeIncoming($query)
    {
        return $query->where('flow_direction', 'INCOMING');
    }

    public function scopeOutgoing($query)
    {
        return $query->where('flow_direction', 'OUTGOING');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['initiated', 'pending', 'processing']);
    }

    public function scopeDuplicates($query)
    {
        return $query->where('is_duplicate', true);
    }

    public function scopeRecentFailures($query, $hours = 24)
    {
        return $query->where('status', 'failed')
                    ->where('created_at', '>=', now()->subHours($hours));
    }

    public function scopeByPhone($query, string $phone)
    {
        return $query->where(function ($q) use ($phone) {
            $q->where('sender_phone', $phone)
              ->orWhere('receiver_phone', $phone);
        });
    }

    // Business Logic Methods
    public function isSuccessful(): bool
    {
        return $this->status === 'completed' && $this->result_code === '0';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed' || 
               ($this->result_code && $this->result_code !== '0');
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['initiated', 'pending', 'processing']);
    }

    public function canRetry(): bool
    {
        return $this->isFailed() && 
               $this->retry_count < 3 && 
               !$this->is_duplicate &&
               in_array($this->transaction_type, ['B2C', 'STK_PUSH']);
    }

    public function markAsCompleted(array $callbackData): bool
    {
        $this->status = 'completed';
        $this->completed_at = now();
        $this->callback_payload = $callbackData;
        $this->callback_received_at = now();
        
        if (isset($callbackData['ResultCode'])) {
            $this->result_code = (string) $callbackData['ResultCode'];
        }
        
        if (isset($callbackData['ResultDesc'])) {
            $this->result_description = $callbackData['ResultDesc'];
        }

        // Calculate processing time
        if ($this->initiated_at) {
            $this->processing_time_ms = $this->initiated_at->diffInMilliseconds(now());
        }

        $success = $this->save();

        // Update linked transaction if successful
        if ($success && $this->isSuccessful() && $this->transaction) {
            $this->transaction->markCompleted();
        }

        return $success;
    }

    public function markAsFailed(string $reason, ?array $errorData = null): bool
    {
        $this->status = 'failed';
        $this->failure_reason = $reason;
        
        if ($errorData) {
            $this->response_payload = array_merge($this->response_payload ?? [], $errorData);
        }

        $success = $this->save();

        // Update linked transaction
        if ($success && $this->transaction) {
            $this->transaction->markFailed($reason);
        }

        return $success;
    }

    public function retry(): bool
    {
        if (!$this->canRetry()) {
            return false;
        }

        $this->retry_count++;
        $this->last_retry_at = now();
        $this->status = 'initiated';
        $this->failure_reason = null;

        return $this->save();
    }

    public function checkForDuplicate(): ?self
    {
        if (!$this->mpesa_receipt_number) {
            return null;
        }

        return static::where('mpesa_receipt_number', $this->mpesa_receipt_number)
                    ->where('id', '!=', $this->id)
                    ->first();
    }

    public function markAsDuplicate(self $originalTransaction): bool
    {
        $this->is_duplicate = true;
        $this->duplicate_of = $originalTransaction->id;
        $this->status = 'duplicate';
        
        return $this->save();
    }

    // Static Methods for M-Pesa Operations
    public static function createC2BLog(array $callbackData): self
    {
        return static::create([
            'transaction_type' => 'C2B',
            'flow_direction' => 'INCOMING',
            'mpesa_transaction_id' => $callbackData['TransID'] ?? null,
            'mpesa_receipt_number' => $callbackData['TransID'] ?? null,
            'sender_phone' => $callbackData['MSISDN'] ?? null,
            'sender_name' => $callbackData['FirstName'] . ' ' . ($callbackData['LastName'] ?? ''),
            'amount' => $callbackData['TransAmount'] ?? 0,
            'status' => 'completed',
            'callback_payload' => $callbackData,
            'callback_received_at' => now(),
            'api_endpoint' => 'c2b/confirmation',
            'ip_address' => request()->ip(),
        ]);
    }

    public static function createB2CLog(array $requestData): self
    {
        return static::create([
            'transaction_type' => 'B2C',
            'flow_direction' => 'OUTGOING',
            'receiver_phone' => $requestData['PartyB'] ?? null,
            'amount' => $requestData['Amount'] ?? 0,
            'status' => 'initiated',
            'request_payload' => $requestData,
            'api_endpoint' => 'b2c/paymentrequest',
            'ip_address' => request()->ip(),
            'created_by_user_id' => auth()->id(),
        ]);
    }

    public static function createStkPushLog(array $requestData): self
    {
        return static::create([
            'transaction_type' => 'STK_PUSH',
            'flow_direction' => 'INCOMING',
            'receiver_phone' => $requestData['PartyB'] ?? auth()->user()->phone ?? null,
            'sender_phone' => $requestData['PartyA'] ?? null,
            'amount' => $requestData['Amount'] ?? 0,
            'status' => 'initiated',
            'request_payload' => $requestData,
            'api_endpoint' => 'stkpush/processrequest',
            'ip_address' => request()->ip(),
            'created_by_user_id' => auth()->id(),
        ]);
    }

    // Analytics Methods
    public static function getTransactionStats(Carbon $startDate, Carbon $endDate): array
    {
        $logs = static::whereBetween('created_at', [$startDate, $endDate])->get();

        return [
            'total_transactions' => $logs->count(),
            'successful_transactions' => $logs->where('status', 'completed')->where('result_code', '0')->count(),
            'failed_transactions' => $logs->where('status', 'failed')->count(),
            'pending_transactions' => $logs->whereIn('status', ['initiated', 'pending', 'processing'])->count(),
            'success_rate' => $logs->count() > 0 ? 
                ($logs->where('status', 'completed')->where('result_code', '0')->count() / $logs->count()) * 100 : 0,
            'total_amount_processed' => $logs->where('status', 'completed')->sum('amount'),
            'average_processing_time' => $logs->where('processing_time_ms', '>', 0)->avg('processing_time_ms'),
            'c2b_count' => $logs->where('transaction_type', 'C2B')->count(),
            'b2c_count' => $logs->where('transaction_type', 'B2C')->count(),
            'stk_push_count' => $logs->where('transaction_type', 'STK_PUSH')->count(),
            'duplicate_count' => $logs->where('is_duplicate', true)->count(),
        ];
    }

    public static function getFailureAnalysis(Carbon $startDate, Carbon $endDate): array
    {
        $failures = static::whereBetween('created_at', [$startDate, $endDate])
                         ->where('status', 'failed')
                         ->get();

        return [
            'total_failures' => $failures->count(),
            'failure_by_type' => $failures->groupBy('transaction_type')->map->count(),
            'common_error_codes' => $failures->groupBy('result_code')->map->count()->sortDesc(),
            'retry_success_rate' => $failures->where('retry_count', '>', 0)->count() > 0 ?
                ($failures->where('retry_count', '>', 0)->where('status', 'completed')->count() / 
                 $failures->where('retry_count', '>', 0)->count()) * 100 : 0,
        ];
    }

    public function getStatusBadgeColor(): string
    {
        return match($this->status) {
            'completed' => $this->result_code === '0' ? 'success' : 'warning',
            'failed', 'cancelled' => 'danger',
            'pending', 'processing' => 'info',
            'initiated' => 'secondary',
            'duplicate' => 'dark',
            default => 'secondary'
        };
    }

    public function getReadableStatus(): string
    {
        if ($this->status === 'completed' && $this->result_code === '0') {
            return 'Success';
        } elseif ($this->status === 'completed' && $this->result_code !== '0') {
            return 'Completed with Error';
        }

        return match($this->status) {
            'failed' => 'Failed',
            'pending' => 'Pending',
            'processing' => 'Processing',
            'initiated' => 'Initiated',
            'duplicate' => 'Duplicate',
            'cancelled' => 'Cancelled',
            default => 'Unknown'
        };
    }
}