<?php

namespace App\Events\MarketEvents;

use App\Models\Market;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MarketCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $market;
    public $timestamp;
    public $eventId;

    public function __construct(Market $market)
    {
        $this->market = $market;
        $this->timestamp = now()->toISOString();
        $this->eventId = uniqid('market_created_', true);

        Log::info('MarketCreated event dispatched', [
            'event_id' => $this->eventId,
            'market_id' => $market->id,
            'market_name' => $market->name,
            'county' => $market->county
        ]);
    }

    public function broadcastOn()
    {
        return ['market.ecosystem'];
    }

    public function broadcastAs()
    {
        return 'market.created';
    }

    public function broadcastWith()
    {
        return [
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
            'event_type' => 'market.created',
            'version' => '1.0',
            'source' => 'farmOS',
            'data' => [
                'market' => [
                    'id' => $this->market->id,
                    'name' => $this->market->name,
                    'market_type' => $this->market->market_type,
                    'county' => $this->market->county,
                    'ward' => $this->market->ward,
                    'location' => $this->market->location,
                    'latitude' => $this->market->latitude,
                    'longitude' => $this->market->longitude,
                    'common_crops' => $this->market->common_crops,
                    'operating_days' => $this->market->operating_days,
                    'opening_time' => $this->market->opening_time,
                    'closing_time' => $this->market->closing_time,
                    'has_cold_storage' => $this->market->has_cold_storage,
                    'has_processing_facility' => $this->market->has_processing_facility,
                    'has_transport_access' => $this->market->has_transport_access,
                    'contact_phone' => $this->market->contact_phone,
                    'contact_person' => $this->market->contact_person,
                    'verification_status' => $this->market->verification_status,
                    'is_active' => $this->market->is_active,
                    'created_at' => $this->market->created_at->toISOString()
                ],
                'metadata' => [
                    'farm_id' => $this->market->farm_id,
                    'created_by' => $this->market->created_by,
                    'tenant_id' => $this->market->tenant_id
                ]
            ]
        ];
    }

    public function getKafkaPayload(): array
    {
        return [
            'topic' => 'market-ecosystem',
            'key' => "market.{$this->market->id}",
            'headers' => [
                'event-type' => 'market.created',
                'source' => 'farmOS',
                'version' => '1.0',
                'content-type' => 'application/json'
            ],
            'payload' => $this->broadcastWith()
        ];
    }
}