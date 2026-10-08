<?php

namespace App\Events\MarketEvents;

use App\Models\MarketPrice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MarketPriceUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $marketPrice;
    public $timestamp;
    public $eventId;
    public $priceChange;

    public function __construct(MarketPrice $marketPrice, ?float $previousPrice = null)
    {
        $this->marketPrice = $marketPrice;
        $this->timestamp = now()->toISOString();
        $this->eventId = uniqid('price_update_', true);
        
        $this->priceChange = $previousPrice ? [
            'previous_price' => $previousPrice,
            'new_price' => $marketPrice->average_price,
            'change_amount' => $marketPrice->average_price - $previousPrice,
            'change_percentage' => $previousPrice > 0 ? 
                (($marketPrice->average_price - $previousPrice) / $previousPrice) * 100 : 0
        ] : null;

        Log::info('MarketPriceUpdated event dispatched', [
            'event_id' => $this->eventId,
            'market_price_id' => $marketPrice->id,
            'market_id' => $marketPrice->market_id,
            'crop_name' => $marketPrice->crop_name,
            'new_price' => $marketPrice->average_price,
            'price_change' => $this->priceChange
        ]);
    }

    public function broadcastOn()
    {
        return [
            'market.ecosystem', 
            'price.intelligence', 
            'farmer.alerts',
            "market.{$this->marketPrice->market_id}.prices"
        ];
    }

    public function broadcastAs()
    {
        return 'market.price_updated';
    }

    public function broadcastWith()
    {
        return [
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
            'event_type' => 'market.price_updated',
            'version' => '1.0',
            'source' => 'farmOS',
            'data' => [
                'market_price' => [
                    'id' => $this->marketPrice->id,
                    'market_id' => $this->marketPrice->market_id,
                    'crop_name' => $this->marketPrice->crop_name,
                    'variety' => $this->marketPrice->variety,
                    'grade' => $this->marketPrice->grade,
                    'unit' => $this->marketPrice->unit,
                    'price_date' => $this->marketPrice->price_date->toISOString(),
                    'prices' => [
                        'min_price' => $this->marketPrice->min_price,
                        'max_price' => $this->marketPrice->max_price,
                        'average_price' => $this->marketPrice->average_price,
                        'modal_price' => $this->marketPrice->modal_price
                    ],
                    'market_conditions' => [
                        'demand_level' => $this->marketPrice->demand_level,
                        'supply_level' => $this->marketPrice->supply_level,
                        'quality_rating' => $this->marketPrice->quality_rating
                    ],
                    'intelligence' => [
                        'price_change_percentage' => $this->marketPrice->price_change_percentage,
                        'predicted_next_price' => $this->marketPrice->predicted_next_price,
                        'confidence_score' => $this->marketPrice->data_confidence,
                        'price_factors' => $this->marketPrice->price_factors
                    ]
                ],
                'price_change' => $this->priceChange,
                'market_info' => [
                    'market_name' => $this->marketPrice->market->name ?? null,
                    'county' => $this->marketPrice->market->county ?? null,
                    'market_type' => $this->marketPrice->market->market_type ?? null
                ],
                'metadata' => [
                    'data_source' => $this->marketPrice->data_source,
                    'collected_by' => $this->marketPrice->collected_by,
                    'tenant_id' => $this->marketPrice->tenant_id
                ]
            ]
        ];
    }

    public function getKafkaPayload(): array
    {
        return [
            'topic' => 'market-price-intelligence',
            'key' => "price.{$this->marketPrice->market_id}.{$this->marketPrice->crop_name}",
            'headers' => [
                'event-type' => 'market.price_updated',
                'source' => 'farmOS',
                'version' => '1.0',
                'market-id' => (string)$this->marketPrice->market_id,
                'crop-name' => $this->marketPrice->crop_name,
                'price-date' => $this->marketPrice->price_date->toDateString(),
                'content-type' => 'application/json'
            ],
            'payload' => $this->broadcastWith()
        ];
    }
}