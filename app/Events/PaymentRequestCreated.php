<?php

namespace App\Events;

use App\Models\PendingPayment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentRequestCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $pendingPayment;
    public $eventData;

    /**
     * Create a new event instance.
     */
    public function __construct(PendingPayment $pendingPayment)
    {
        $this->pendingPayment = $pendingPayment;
        $this->eventData = $this->prepareEventData($pendingPayment);
    }

    /**
     * Prepare event data for Kafka publication
     */
    private function prepareEventData(PendingPayment $pendingPayment): array
    {
        return [
            'event_type' => 'payment_request.created',
            'event_id' => (string) \Str::uuid(),
            'timestamp' => now()->toISOString(),
            'farm_id' => auth()->user()->farm_id ?? $pendingPayment->requestedBy->farm_id ?? null,
            'user_id' => $pendingPayment->requested_by_user_id,
            'payment_request' => [
                'id' => $pendingPayment->id,
                'reference' => $pendingPayment->payment_reference,
                'type' => $pendingPayment->payment_type,
                'amount' => $pendingPayment->amount,
                'currency' => $pendingPayment->currency,
                'priority' => $pendingPayment->priority,
                'status' => $pendingPayment->status,
                'payment_method' => $pendingPayment->preferred_payment_method,
                'recipient_name' => $pendingPayment->recipient_name,
                'recipient_phone' => $pendingPayment->recipient_phone,
                'payment_reason' => $pendingPayment->payment_reason,
                'scheduled_date' => $pendingPayment->scheduled_payment_date?->toISOString(),
                'linked_type' => $pendingPayment->linked_type,
                'linked_id' => $pendingPayment->linked_id,
                'budget_category' => $pendingPayment->budget_category,
                'exceeds_budget' => $pendingPayment->exceeds_budget,
                'requested_at' => $pendingPayment->requested_at->toISOString(),
            ],
            'approval_workflow' => [
                'requires_manager_approval' => $pendingPayment->requiresManagerApproval(),
                'requires_owner_approval' => $pendingPayment->requiresOwnerApproval(),
                'current_status' => $pendingPayment->status,
                'is_auto_approved' => $pendingPayment->status === 'approved',
            ],
            'metadata' => [
                'source' => 'farmOS',
                'version' => '1.0',
                'module' => 'financial_transactions',
                'urgency_level' => $this->getUrgencyLevel($pendingPayment),
                'is_recurring' => $pendingPayment->is_recurring,
                'days_until_due' => $pendingPayment->getDaysUntilDue(),
            ]
        ];
    }

    /**
     * Determine urgency level based on priority and due date
     */
    private function getUrgencyLevel(PendingPayment $pendingPayment): string
    {
        if ($pendingPayment->priority === 'urgent' || $pendingPayment->isOverdue()) {
            return 'critical';
        }

        if ($pendingPayment->priority === 'high' || $pendingPayment->getDaysUntilDue() <= 1) {
            return 'high';
        }

        if ($pendingPayment->getDaysUntilDue() <= 3) {
            return 'medium';
        }

        return 'low';
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
            'type' => 'payment_request.created',
            'payment_request' => [
                'id' => $this->pendingPayment->id,
                'reference' => $this->pendingPayment->payment_reference,
                'amount' => $this->pendingPayment->amount,
                'priority' => $this->pendingPayment->priority,
                'status' => $this->pendingPayment->status,
                'recipient_name' => $this->pendingPayment->recipient_name,
                'payment_reason' => $this->pendingPayment->payment_reason,
            ],
            'timestamp' => now()->toISOString()
        ];
    }
}