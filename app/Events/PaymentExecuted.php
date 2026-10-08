<?php

namespace App\Events;

use App\Models\PendingPayment;
use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentExecuted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $pendingPayment;
    public $transaction;
    public $eventData;

    /**
     * Create a new event instance.
     */
    public function __construct(PendingPayment $pendingPayment, ?Transaction $transaction = null)
    {
        $this->pendingPayment = $pendingPayment;
        $this->transaction = $transaction ?? $pendingPayment->transaction;
        $this->eventData = $this->prepareEventData($pendingPayment, $this->transaction);
    }

    /**
     * Prepare event data for Kafka publication
     */
    private function prepareEventData(PendingPayment $pendingPayment, ?Transaction $transaction): array
    {
        return [
            'event_type' => 'payment.executed',
            'event_id' => (string) \Str::uuid(),
            'timestamp' => now()->toISOString(),
            'farm_id' => auth()->user()->farm_id ?? $pendingPayment->requestedBy->farm_id ?? null,
            'user_id' => auth()->id(),
            'payment_request' => [
                'id' => $pendingPayment->id,
                'reference' => $pendingPayment->payment_reference,
                'type' => $pendingPayment->payment_type,
                'amount' => $pendingPayment->amount,
                'currency' => $pendingPayment->currency,
                'payment_method' => $pendingPayment->preferred_payment_method,
                'recipient_name' => $pendingPayment->recipient_name,
                'recipient_phone' => $pendingPayment->recipient_phone,
                'payment_reason' => $pendingPayment->payment_reason,
                'executed_at' => $pendingPayment->payment_initiated_at?->toISOString(),
                'status' => $pendingPayment->status,
            ],
            'transaction' => $transaction ? [
                'id' => $transaction->id,
                'reference' => $transaction->transaction_reference,
                'type' => $transaction->type,
                'category' => $transaction->category,
                'amount' => $transaction->amount,
                'net_amount' => $transaction->net_amount,
                'payment_method' => $transaction->payment_method,
                'status' => $transaction->status,
                'phone_number' => $transaction->phone_number,
                'mpesa_type' => $transaction->mpesa_type,
                'created_at' => $transaction->created_at->toISOString(),
            ] : null,
            'payment_execution' => [
                'execution_method' => $this->determineExecutionMethod($pendingPayment),
                'requires_external_processing' => in_array($pendingPayment->preferred_payment_method, ['mpesa', 'bank_transfer']),
                'is_instant_payment' => $pendingPayment->preferred_payment_method === 'cash',
                'processing_time_estimate' => $this->getProcessingTimeEstimate($pendingPayment->preferred_payment_method),
            ],
            'linked_entity' => $this->getLinkedEntityData($pendingPayment),
            'metadata' => [
                'source' => 'farmOS',
                'version' => '1.0',
                'module' => 'financial_transactions',
                'execution_trigger' => 'manual', // Could be 'automatic' for scheduled payments
                'priority' => $pendingPayment->priority,
                'is_recurring_payment' => $pendingPayment->is_recurring,
                'budget_impact' => $pendingPayment->budget_category,
            ]
        ];
    }

    /**
     * Determine the execution method
     */
    private function determineExecutionMethod(PendingPayment $pendingPayment): string
    {
        return match($pendingPayment->preferred_payment_method) {
            'mpesa' => 'mpesa_b2c',
            'bank_transfer' => 'bank_transfer',
            'cash' => 'cash_disbursement',
            'cheque' => 'cheque_issuance',
            default => 'manual_processing'
        };
    }

    /**
     * Get processing time estimate in minutes
     */
    private function getProcessingTimeEstimate(string $paymentMethod): int
    {
        return match($paymentMethod) {
            'cash' => 0, // Instant
            'mpesa' => 5, // 5 minutes
            'bank_transfer' => 1440, // 24 hours
            'cheque' => 2880, // 48 hours
            default => 60 // 1 hour
        };
    }

    /**
     * Get linked entity data
     */
    private function getLinkedEntityData(PendingPayment $pendingPayment): ?array
    {
        if (!$pendingPayment->linked_type || !$pendingPayment->linked_id) {
            return null;
        }

        return [
            'type' => $pendingPayment->linked_type,
            'id' => $pendingPayment->linked_id,
            'metadata' => $pendingPayment->linked_metadata,
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('farm.' . ($this->pendingPayment->requestedBy->farm_id ?? 'default')),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'payment.executed',
            'payment_request' => [
                'id' => $this->pendingPayment->id,
                'reference' => $this->pendingPayment->payment_reference,
                'amount' => $this->pendingPayment->amount,
                'recipient_name' => $this->pendingPayment->recipient_name,
                'payment_method' => $this->pendingPayment->preferred_payment_method,
            ],
            'transaction_id' => $this->transaction?->id,
            'timestamp' => now()->toISOString()
        ];
    }
}