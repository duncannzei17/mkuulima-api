<?php

namespace App\Events\BuyerEvents;

use App\Models\BuyerOffer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BuyerOfferCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $buyerOffer;
    public $timestamp;
    public $eventId;

    public function __construct(BuyerOffer $buyerOffer)
    {
        $this->buyerOffer = $buyerOffer;
        $this->timestamp = now()->toISOString();
        $this->eventId = uniqid('buyer_offer_', true);

        Log::info('BuyerOfferCreated event dispatched', [
            'event_id' => $this->eventId,
            'buyer_offer_id' => $buyerOffer->id,
            'buyer_name' => $buyerOffer->buyer_name,
            'crop_name' => $buyerOffer->crop_name,
            'offered_price' => $buyerOffer->offered_price_per_unit
        ]);
    }

    public function broadcastOn()
    {
        $channels = ['market.ecosystem', 'farmer.notifications'];
        
        // Add specific farmer channel if sell order exists
        if ($this->buyerOffer->sell_order_id) {
            $channels[] = "sell_order.{$this->buyerOffer->sell_order_id}.offers";
        }

        return $channels;
    }

    public function broadcastAs()
    {
        return 'buyer.offer_created';
    }

    public function broadcastWith()
    {
        return [
            'event_id' => $this->eventId,
            'timestamp' => $this->timestamp,
            'event_type' => 'buyer.offer_created',
            'version' => '1.0',
            'source' => 'farmOS',
            'data' => [
                'buyer_offer' => [
                    'id' => $this->buyerOffer->id,
                    'sell_order_id' => $this->buyerOffer->sell_order_id,
                    'buyer_info' => [
                        'buyer_name' => $this->buyerOffer->buyer_name,
                        'buyer_phone' => $this->buyerOffer->buyer_phone,
                        'buyer_type' => $this->buyerOffer->buyer_type,
                        'company_name' => $this->buyerOffer->company_name,
                        'reliability_score' => $this->buyerOffer->buyer_reliability_score
                    ],
                    'offer_details' => [
                        'crop_name' => $this->buyerOffer->crop_name,
                        'variety' => $this->buyerOffer->variety,
                        'quantity_requested' => $this->buyerOffer->quantity_requested,
                        'unit' => $this->buyerOffer->unit,
                        'grade_preference' => $this->buyerOffer->grade_preference,
                        'offered_price_per_unit' => $this->buyerOffer->offered_price_per_unit,
                        'total_offer_amount' => $this->buyerOffer->total_offer_amount
                    ],
                    'logistics' => [
                        'pickup_preferred' => $this->buyerOffer->pickup_preferred,
                        'delivery_address' => $this->buyerOffer->delivery_address,
                        'delivery_latitude' => $this->buyerOffer->delivery_latitude,
                        'delivery_longitude' => $this->buyerOffer->delivery_longitude,
                        'transport_cost_covered_by_buyer' => $this->buyerOffer->transport_cost_covered_by_buyer
                    ],
                    'timing' => [
                        'pickup_date_requested' => $this->buyerOffer->pickup_date_requested,
                        'offer_expires_at' => $this->buyerOffer->offer_expires_at,
                        'created_at' => $this->buyerOffer->created_at->toISOString()
                    ],
                    'payment_terms' => [
                        'payment_method' => $this->buyerOffer->payment_method,
                        'payment_terms' => $this->buyerOffer->payment_terms,
                        'advance_payment_percentage' => $this->buyerOffer->advance_payment_percentage
                    ],
                    'status' => $this->buyerOffer->status
                ],
                'ai_matching_score' => $this->buyerOffer->ai_matching_score,
                'special_requirements' => $this->buyerOffer->special_requirements,
                'metadata' => [
                    'market_id' => $this->buyerOffer->market_id,
                    'tenant_id' => $this->buyerOffer->tenant_id,
                    'offer_source' => $this->buyerOffer->offer_source
                ]
            ]
        ];
    }

    public function getKafkaPayload(): array
    {
        return [
            'topic' => 'buyer-offers',
            'key' => "buyer_offer.{$this->buyerOffer->id}",
            'headers' => [
                'event-type' => 'buyer.offer_created',
                'source' => 'farmOS',
                'version' => '1.0',
                'crop-name' => $this->buyerOffer->crop_name,
                'buyer-type' => $this->buyerOffer->buyer_type,
                'offer-amount' => (string)$this->buyerOffer->total_offer_amount,
                'content-type' => 'application/json'
            ],
            'payload' => $this->broadcastWith()
        ];
    }
}