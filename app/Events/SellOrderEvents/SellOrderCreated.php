<?php

namespace App\Events\SellOrderEvents;

use App\Models\SellOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SellOrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $sellOrder;
    public $timestamp;
    public $eventId;

    public function __construct(SellOrder $sellOrder)
    {
        $this->sellOrder = $sellOrder;
        $this->timestamp = now()->toISOString();
        $this->eventId = uniqid('sell_order_created_', true);

        Log::info('SellOrderCreated event dispatched', [
            'event_id' => $this->eventId,
            'sell_order_id' => $sellOrder->id,
            'order_number' => $sellOrder->order_number,
            'crop_name' => $sellOrder->crop_name,
            'quantity' => $sellOrder->quantity
        ]);
    }

    public function broadcastOn()
    {
        return ['market.ecosystem', 'logistics.ecosystem', 'buyer.ecosystem'];
    }

    public function broadcastAs()
    {
        return 'sell_order.created';
    }

    public function broadcastWith()
    {
        return [
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
            'event_type' => 'sell_order.created',
            'version' => '1.0',
            'source' => 'farmOS',
            'data' => [
                'sell_order' => [
                    'id' => $this->sellOrder->id,
                    'order_number' => $this->sellOrder->order_number,
                    'crop_name' => $this->sellOrder->crop_name,
                    'variety' => $this->sellOrder->crop_variety,
                    'quantity' => $this->sellOrder->quantity,
                    'unit' => $this->sellOrder->unit,
                    'grade' => $this->sellOrder->grade,
                    'min_price_per_unit' => $this->sellOrder->minimum_acceptable_price,
                    'preferred_price_per_unit' => $this->sellOrder->asking_price_per_unit,
                    'market_id' => $this->sellOrder->market_id,
                    'pickup_location' => [
                        'address' => $this->sellOrder->pickup_location,
                        'latitude' => $this->sellOrder->pickup_latitude,
                        'longitude' => $this->sellOrder->pickup_longitude
                    ],
                    'farmer_info' => [
                        'name' => $this->sellOrder->createdBy?->name,
                        'phone' => $this->sellOrder->createdBy?->phone
                    ],
                    'timing' => [
                        'preferred_pickup_time' => $this->sellOrder->preferred_pickup_time
                    ],
                    'status' => $this->sellOrder->status,
                    'created_at' => $this->sellOrder->created_at->toISOString()
                ],
                'metadata' => [
                    'farm_id' => request()->header('X-Tenant-ID'),
                    'crop_cycle_id' => $this->sellOrder->crop_cycle_id,
                    'harvest_id' => $this->sellOrder->harvest_id,
                    'tenant_id' => request()->header('X-Tenant-ID')
                ]
            ]
        ];
    }

    public function getKafkaPayload(): array
    {
        return [
            'topic' => 'sell-order-ecosystem',
            'key' => "sell_order.{$this->sellOrder->id}",
            'headers' => [
                'event-type' => 'sell_order.created',
                'source' => 'farmOS',
                'version' => '1.0',
                'content-type' => 'application/json'
            ],
            'payload' => $this->broadcastWith()
        ];
    }
}
