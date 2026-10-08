<?php

namespace App\Events;

use App\Models\Transaction;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionStatusUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;
    public $previousStatus;
    public $eventData;

    /**
     * Create a new event instance.
     */
    public function __construct(Transaction $transaction, string $previousStatus)
    {
        $this->transaction = $transaction;
        $this->previousStatus = $previousStatus;
        $this->eventData = $this->prepareEventData($transaction, $previousStatus);
    }

    /**
     * Prepare event data for Kafka publication
     */
    private function prepareEventData(Transaction $transaction, string $previousStatus): array
    {
        return [
            'event_type' => 'transaction.status_updated',
            'event_id' => (string) \Str::uuid(),
            'timestamp' => now()->toISOString(),
            'farm_id' => auth()->user()->farm_id ?? $transaction->createdBy->farm_id ?? null,
            'user_id' => auth()->id(),
            'transaction' => [
                'id' => $transaction->id,
                'reference' => $transaction->transaction_reference,
                'type' => $transaction->type,
                'amount' => $transaction->amount,
                'payment_method' => $transaction->payment_method,
                'previous_status' => $previousStatus,
                'current_status' => $transaction->status,
                'status_changed_at' => now()->toISOString(),
                'approved_by' => $transaction->approved_by_user_id,
                'approved_at' => $transaction->approved_at?->toISOString(),
                'linked_type' => $transaction->linked_type,
                'linked_id' => $transaction->linked_id,
            ],
            'wallet_impact' => $this->calculateWalletImpact($transaction),
            'metadata' => [
                'source' => 'farmOS',
                'version' => '1.0',
                'module' => 'financial_transactions',
                'status_transition' => "{$previousStatus} -> {$transaction->status}",
                'is_completion' => $transaction->status === 'completed',
                'is_failure' => $transaction->status === 'failed',
                'is_approval' => in_array($transaction->status, ['approved', 'rejected']),
                'requires_wallet_update' => $transaction->status === 'completed',
            ]
        ];
    }

    /**
     * Calculate potential wallet impact
     */
    private function calculateWalletImpact(Transaction $transaction): ?array
    {
        if ($transaction->status !== 'completed') {
            return null;
        }

        $impact = $transaction->type === 'income' ? $transaction->net_amount : -$transaction->net_amount;

        return [
            'payment_method' => $transaction->payment_method,
            'amount_impact' => $impact,
            'currency' => $transaction->currency,
            'net_amount' => $transaction->net_amount,
            'fees' => $transaction->fees,
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('farm.' . ($this->transaction->createdBy->farm_id ?? 'default')),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'type' => 'transaction.status_updated',
            'transaction' => [
                'id' => $this->transaction->id,
                'reference' => $this->transaction->transaction_reference,
                'previous_status' => $this->previousStatus,
                'current_status' => $this->transaction->status,
                'amount' => $this->transaction->amount,
                'payment_method' => $this->transaction->payment_method,
            ],
            'timestamp' => now()->toISOString()
        ];
    }
}