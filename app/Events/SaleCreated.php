<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SaleCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Sale $sale;
    
    public function __construct(Sale $sale)
    {
        $this->sale = $sale;
    }

    /**
     * Get the data for Kafka event
     */
    public function toKafka(): array
    {
        return [
            'event_type' => 'sale.created',
            'event_id' => $this->sale->id,
            'timestamp' => now()->toISOString(),
            'module' => 'sales_income_tracking',
            'tenant' => request()->get('tenant_id'),
            'data' => [
                'sale_id' => $this->sale->id,
                'sale_date' => $this->sale->sale_date->toDateString(),
                'crop_cycle_id' => $this->sale->crop_cycle_id,
                'buyer_id' => $this->sale->buyer_id,
                'buyer_name' => $this->sale->buyer_name,
                'quantity_sold' => $this->sale->quantity_sold,
                'unit' => $this->sale->unit,
                'price_per_unit' => $this->sale->price_per_unit,
                'gross_income' => $this->sale->gross_income,
                'net_income' => $this->sale->net_income,
                'sale_type' => $this->sale->sale_type,
                'payment_method' => $this->sale->payment_method,
                'payment_status' => $this->sale->payment_status,
                'status' => $this->sale->status,
                'market_location' => $this->sale->market_location,
                'created_by' => $this->sale->created_by,
                'created_at' => $this->sale->created_at->toISOString()
            ]
        ];
    }
}