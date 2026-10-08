<?php

namespace App\Events;

use App\Models\PriceHistory;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PriceHistoryRecorded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public PriceHistory $priceHistory;
    
    public function __construct(PriceHistory $priceHistory)
    {
        $this->priceHistory = $priceHistory;
    }

    /**
     * Get the data for Kafka event
     */
    public function toKafka(): array
    {
        return [
            'event_type' => 'price.recorded',
            'event_id' => $this->priceHistory->id,
            'timestamp' => now()->toISOString(),
            'module' => 'sales_income_tracking',
            'tenant' => request()->get('tenant_id'),
            'data' => [
                'price_history_id' => $this->priceHistory->id,
                'crop_name' => $this->priceHistory->crop_name,
                'variety' => $this->priceHistory->variety,
                'unit' => $this->priceHistory->unit,
                'price_per_unit' => $this->priceHistory->price_per_unit,
                'price_date' => $this->priceHistory->price_date->toDateString(),
                'market_location' => $this->priceHistory->market_location,
                'price_type' => $this->priceHistory->price_type,
                'quality_grade' => $this->priceHistory->quality_grade,
                'quantity_sold' => $this->priceHistory->quantity_sold,
                'season' => $this->priceHistory->season,
                'demand_level' => $this->priceHistory->demand_level,
                'supply_level' => $this->priceHistory->supply_level,
                'sale_id' => $this->priceHistory->sale_id,
                'created_by' => $this->priceHistory->created_by,
                'created_at' => $this->priceHistory->created_at->toISOString(),
                'market_conditions' => $this->priceHistory->market_conditions
            ]
        ];
    }
}