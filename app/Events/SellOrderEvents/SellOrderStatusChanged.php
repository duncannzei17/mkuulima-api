<?php

namespace App\Events\SellOrderEvents;

use App\Models\SellOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SellOrderStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sellOrder;
    public $oldStatus;
    public $newStatus;
    public $timestamp;
    public $eventId;
    public $statusMetadata;

    public function __construct(SellOrder $sellOrder, string $oldStatus, array $statusMetadata = [])
    {
        $this->sellOrder = $sellOrder;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $sellOrder->status;
        $this->statusMetadata = $statusMetadata;
        $this->timestamp = now()->toISOString();
        $this->eventId = uniqid('sell_order_status_', true);

        Log::info('SellOrderStatusChanged event dispatched', [
            'event_id' => $this->eventId,
            'sell_order_id' => $sellOrder->id,
            'order_number' => $sellOrder->order_number,
            'old_status' => $oldStatus,
            'new_status' => $this->newStatus,
            'metadata' => $statusMetadata
        ]);
    }

    public function broadcastOn()
    {
        $channels = ['market.ecosystem', 'farmer.notifications'];
        
        // Add logistics channel for relevant statuses
        if (in_array($this->newStatus, ['logistics_assigned', 'picked_up', 'in_transit', 'delivered'])) {
            $channels[] = 'logistics.ecosystem';
        }
        
        // Add buyer channel if buyer is involved
        if ($this->sellOrder->buyer_id) {
            $channels[] = 'buyer.notifications';
        }

        return $channels;
    }

    public function broadcastAs()
    {
        return 'sell_order.status_changed';
    }

    public function broadcastWith()
    {
        return [
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
            'event_type' => 'sell_order.status_changed',
            'version' => '1.0',
            'source' => 'farmOS',
            'data' => [
                'sell_order' => [
                    'id' => $this->sellOrder->id,
                    'order_number' => $this->sellOrder->order_number,
                    'crop_name' => $this->sellOrder->crop_name,
                    'quantity' => $this->sellOrder->quantity,
                    'unit' => $this->sellOrder->unit
                ],
                'status_change' => [
                    'old_status' => $this->oldStatus,
                    'new_status' => $this->newStatus,
                    'changed_at' => $this->timestamp,
                    'metadata' => $this->statusMetadata
                ],
                'logistics_info' => [
                    'logistics_id' => $this->sellOrder->logistics_order_id,
                    'tracking_code' => $this->sellOrder->logistics_order_id,
                    'logistics_provider' => $this->sellOrder->logistics_provider
                ],
                'buyer_info' => $this->sellOrder->buyer_id ? [
                    'buyer_id' => $this->sellOrder->buyer_id,
                    'buyer_name' => $this->sellOrder->buyer?->name,
                    'buyer_phone' => $this->sellOrder->buyer?->phone
                ] : null,
                'financial_info' => [
                    'agreed_price_per_unit' => $this->sellOrder->agreed_price_per_unit,
                    'agreed_total_price' => $this->sellOrder->total_value,
                    'transport_cost' => $this->sellOrder->transport_cost,
                    'platform_commission' => $this->sellOrder->commission_amount,
                    'net_amount_to_farmer' => $this->sellOrder->net_amount
                ],
                'metadata' => [
                    'farm_id' => request()->header('X-Tenant-ID'),
                    'market_id' => $this->sellOrder->market_id,
                    'tenant_id' => request()->header('X-Tenant-ID')
                ]
            ]
        ];
    }

    public function getKafkaPayload(): array
    {
        return [
            'topic' => 'sell-order-status',
            'key' => "sell_order.{$this->sellOrder->id}.status",
            'headers' => [
                'event-type' => 'sell_order.status_changed',
                'source' => 'farmOS',
                'version' => '1.0',
                'old-status' => $this->oldStatus,
                'new-status' => $this->newStatus,
                'content-type' => 'application/json'
            ],
            'payload' => $this->broadcastWith()
        ];
    }
}
