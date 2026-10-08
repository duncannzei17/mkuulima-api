<?php

namespace App\Events;

use App\Models\Buyer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BuyerCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Buyer $buyer;
    
    public function __construct(Buyer $buyer)
    {
        $this->buyer = $buyer;
    }

    /**
     * Get the data for Kafka event
     */
    public function toKafka(): array
    {
        return [
            'event_type' => 'buyer.created',
            'event_id' => $this->buyer->id,
            'timestamp' => now()->toISOString(),
            'module' => 'sales_income_tracking',
            'tenant' => request()->get('tenant_id'),
            'data' => [
                'buyer_id' => $this->buyer->id,
                'name' => $this->buyer->name,
                'type' => $this->buyer->type,
                'location' => $this->buyer->location,
                'phone' => $this->buyer->phone,
                'email' => $this->buyer->email,
                'payment_terms' => $this->buyer->payment_terms,
                'credit_limit' => $this->buyer->credit_limit,
                'is_active' => $this->buyer->is_active,
                'created_by' => $this->buyer->created_by,
                'created_at' => $this->buyer->created_at->toISOString(),
                'contact_info' => $this->buyer->contact_info
            ]
        ];
    }
}