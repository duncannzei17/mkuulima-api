<?php

namespace App\Events;

use App\Models\Sale;
use App\Models\SalesApproval;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SaleApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Sale $sale;
    public SalesApproval $approval;
    
    public function __construct(Sale $sale, SalesApproval $approval)
    {
        $this->sale = $sale;
        $this->approval = $approval;
    }

    /**
     * Get the data for Kafka event
     */
    public function toKafka(): array
    {
        return [
            'event_type' => 'sale.approved',
            'event_id' => $this->sale->id,
            'timestamp' => now()->toISOString(),
            'module' => 'sales_income_tracking',
            'tenant' => request()->get('tenant_id'),
            'data' => [
                'sale_id' => $this->sale->id,
                'approval_id' => $this->approval->id,
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
                'market_location' => $this->sale->market_location,
                'approved_by' => $this->approval->approved_by,
                'approval_reason' => $this->approval->reason,
                'approved_at' => $this->approval->action_taken_at->toISOString(),
                'changes_made' => $this->approval->changes_made,
                'revenue_impact' => [
                    'gross_revenue' => $this->sale->gross_income,
                    'net_revenue' => $this->sale->net_income,
                    'deductions' => $this->sale->total_deductions
                ]
            ]
        ];
    }
}