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

class TransactionCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $transaction;
    public $eventData;

    /**
     * Create a new event instance.
     */
    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
        $this->eventData = $this->prepareEventData($transaction);
    }

    /**
     * Prepare event data for Kafka publication
     */
    private function prepareEventData(Transaction $transaction): array
    {
        return [
            'event_type' => 'transaction.created',
            'event_id' => (string) \Str::uuid(),
            'timestamp' => now()->toISOString(),
            'farm_id' => auth()->user()->farm_id ?? null,
            'user_id' => $transaction->created_by_user_id,
            'transaction' => [
                'id' => $transaction->id,
                'reference' => $transaction->transaction_reference,
                'type' => $transaction->type,
                'category' => $transaction->category,
                'amount' => $transaction->amount,
                'net_amount' => $transaction->net_amount,
                'payment_method' => $transaction->payment_method,
                'status' => $transaction->status,
                'description' => $transaction->description,
                'transaction_date' => $transaction->transaction_date->toISOString(),
                'linked_type' => $transaction->linked_type,
                'linked_id' => $transaction->linked_id,
                'phone_number' => $transaction->phone_number,
                'currency' => $transaction->currency,
                'fees' => $transaction->fees,
                'mpesa_type' => $transaction->mpesa_type,
                'created_at' => $transaction->created_at->toISOString(),
            ],
            'metadata' => [
                'source' => 'farmOS',
                'version' => '1.0',
                'module' => 'financial_transactions',
                'requires_approval' => $transaction->requiresApproval(),
                'auto_approved' => $transaction->status === 'approved' && $transaction->created_at->eq($transaction->approved_at ?? now()),
            ]
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
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
            'type' => 'transaction.created',
            'transaction' => [
                'id' => $this->transaction->id,
                'reference' => $this->transaction->transaction_reference,
                'amount' => $this->transaction->amount,
                'type' => $this->transaction->type,
                'status' => $this->transaction->status,
                'payment_method' => $this->transaction->payment_method,
            ],
            'timestamp' => now()->toISOString()
        ];
    }
}