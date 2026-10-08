<?php

namespace App\Events\SellOrderEvents;

use App\Models\SellOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SellOrderDispatchRequested implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sellOrder;
    public $logisticsPayload;
    public $timestamp;
    public $eventId;

    public function __construct(SellOrder $sellOrder, array $logisticsPayload)
    {
        $this->sellOrder = $sellOrder;
        $this->logisticsPayload = $logisticsPayload;
        $this->timestamp = now()->toISOString();
        $this->eventId = uniqid('dispatch_request_', true);

        Log::info('SellOrderDispatchRequested event dispatched', [
            'event_id' => $this->eventId,
            'sell_order_id' => $sellOrder->id,
            'order_number' => $sellOrder->order_number,
            'logistics_provider' => $sellOrder->logistics_provider
        ]);
    }

    public function broadcastOn()
    {
        return ['logistics.ecosystem', 'siku_mpya.dispatch'];
    }

    public function broadcastAs()
    {
        return 'sell_order.dispatch_requested';
    }

    public function broadcastWith()
    {
        return [
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
            'event_type' => 'sell_order.dispatch_requested',
            'version' => '1.0',
            'source' => 'farmOS',
            'data' => [
                'sell_order' => [
                    'id' => $this->sellOrder->id,
                    'order_number' => $this->sellOrder->order_number,
                    'status' => $this->sellOrder->status
                ],
                'logistics_request' => $this->logisticsPayload,
                'priority' => $this->determineDispatchPriority(),
                'metadata' => [
                    'farm_id' => $this->logisticsPayload['tenant_id'] ?? null,
                    'tenant_id' => $this->logisticsPayload['tenant_id'] ?? null,
                    'requested_at' => $this->timestamp
                ]
            ]
        ];
    }

    private function determineDispatchPriority(): string
    {
        $pickupTime = $this->sellOrder->preferred_pickup_time;
        $hoursLeft = $pickupTime ? now()->diffInHours($pickupTime, false) : 48;

        if ($hoursLeft <= 4) {
            return 'urgent';
        } elseif ($hoursLeft <= 12) {
            return 'high';
        } elseif ($hoursLeft <= 24) {
            return 'medium';
        } else {
            return 'normal';
        }
    }

    public function getKafkaPayload(): array
    {
        return [
            'topic' => 'logistics-dispatch-requests',
            'key' => "dispatch.{$this->sellOrder->id}",
            'headers' => [
                'event-type' => 'sell_order.dispatch_requested',
                'source' => 'farmOS',
                'version' => '1.0',
                'priority' => $this->determineDispatchPriority(),
                'logistics-provider' => $this->sellOrder->logistics_provider,
                'content-type' => 'application/json'
            ],
            'payload' => $this->broadcastWith()
        ];
    }
}
