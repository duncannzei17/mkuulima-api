<?php

namespace App\Models;

use App\Events\ProfitabilityCalculated;
use App\Traits\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfitabilitySnapshot extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'crop_cycle_id', 'farm_id', 'total_expenses', 'labour_cost', 'input_cost',
        'inventory_cost', 'transport_cost', 'processing_cost', 'storage_cost',
        'miscellaneous_cost', 'total_revenue', 'gross_sales', 'deductions_amount',
        'net_sales', 'cost_of_production', 'gross_profit', 'net_profit',
        'profit_margin', 'roi_percentage', 'expected_yield', 'actual_yield',
        'yield_efficiency', 'yield_unit', 'profit_per_kg', 'profit_per_bed',
        'profit_per_acre', 'cost_per_kg', 'revenue_per_kg', 'total_area',
        'area_unit', 'number_of_beds', 'labour_cost_percentage',
        'input_cost_percentage', 'inventory_cost_percentage',
        'transport_cost_percentage', 'other_cost_percentage', 'season_health_score',
        'efficiency_score', 'cost_efficiency_score', 'yield_performance_score',
        'average_selling_price', 'market_price_variance', 'price_realization',
        'crop_duration_days', 'cycle_start_date', 'cycle_end_date',
        'first_harvest_date', 'last_harvest_date', 'calculation_status',
        'calculation_metadata', 'cost_breakdown', 'revenue_breakdown',
        'performance_indicators', 'insights', 'recommendations', 'calculated_at',
    ];

    protected $casts = [
        'total_expenses' => 'decimal:2', 'labour_cost' => 'decimal:2',
        'input_cost' => 'decimal:2', 'inventory_cost' => 'decimal:2',
        'transport_cost' => 'decimal:2', 'processing_cost' => 'decimal:2',
        'storage_cost' => 'decimal:2', 'miscellaneous_cost' => 'decimal:2',
        'total_revenue' => 'decimal:2', 'gross_sales' => 'decimal:2',
        'deductions_amount' => 'decimal:2', 'net_sales' => 'decimal:2',
        'cost_of_production' => 'decimal:2', 'gross_profit' => 'decimal:2',
        'net_profit' => 'decimal:2', 'profit_margin' => 'decimal:2',
        'roi_percentage' => 'decimal:2', 'expected_yield' => 'decimal:3',
        'actual_yield' => 'decimal:3', 'yield_efficiency' => 'decimal:2',
        'profit_per_kg' => 'decimal:2', 'profit_per_bed' => 'decimal:2',
        'profit_per_acre' => 'decimal:2', 'cost_per_kg' => 'decimal:2',
        'revenue_per_kg' => 'decimal:2', 'total_area' => 'decimal:2',
        'labour_cost_percentage' => 'decimal:2', 'input_cost_percentage' => 'decimal:2',
        'inventory_cost_percentage' => 'decimal:2', 'transport_cost_percentage' => 'decimal:2',
        'other_cost_percentage' => 'decimal:2', 'season_health_score' => 'decimal:2',
        'efficiency_score' => 'decimal:2', 'cost_efficiency_score' => 'decimal:2',
        'yield_performance_score' => 'decimal:2', 'average_selling_price' => 'decimal:2',
        'market_price_variance' => 'decimal:2', 'price_realization' => 'decimal:2',
        'cycle_start_date' => 'date', 'cycle_end_date' => 'date',
        'first_harvest_date' => 'date', 'last_harvest_date' => 'date',
        'calculation_metadata' => 'array', 'cost_breakdown' => 'array',
        'revenue_breakdown' => 'array', 'performance_indicators' => 'array',
        'calculated_at' => 'datetime',
    ];

    public function cropCycle(): BelongsTo
    {
        return $this->belongsTo(CropCycle::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function calculateProfitability(): array
    {
        $cropCycle = $this->cropCycle;
        if (!$cropCycle) {
            throw new \RuntimeException('Crop cycle not found for profitability calculation');
        }

        $expenses = Expense::approved()
            ->where('crop_cycle_id', $cropCycle->id)
            ->where('is_deleted', false)
            ->where(function ($query) {
                $query->whereNull('metadata')->orWhereRaw("metadata->>'labour_entry_id' IS NULL");
            })
            ->with('category:id,name,slug')
            ->get();
        $directExpenses = (float) $expenses->sum('amount');
        $inputExpenses = (float) $expenses->filter(function (Expense $expense) {
            $label = strtolower(($expense->category?->name ?? '') . ' ' . ($expense->category?->slug ?? '') . ' ' . ($expense->subcategory ?? ''));
            return str_contains($label, 'input') || str_contains($label, 'seed')
                || str_contains($label, 'fertil') || str_contains($label, 'chemical')
                || str_contains($label, 'pesticide') || str_contains($label, 'manure');
        })->sum('amount');

        $labourEntries = LabourEntry::approved()->where('crop_cycle_id', $cropCycle->id)->get();
        $labourCost = (float) $labourEntries->sum(fn (LabourEntry $entry) => $entry->total_amount);
        $inventoryCost = (float) StockMovement::where('crop_cycle_id', $cropCycle->id)
            ->whereIn('movement_type', ['out', 'expired_removal'])
            ->sum('total_cost');

        $approvedSales = Sale::approved()->where('crop_cycle_id', $cropCycle->id);
        $grossSales = (float) (clone $approvedSales)->sum('gross_income');
        $deductions = (float) (clone $approvedSales)->sum('total_deductions');
        $netSales = (float) (clone $approvedSales)->sum('net_income');
        $averagePrice = (float) (clone $approvedSales)->avg('price_per_unit');
        $transportCost = (float) SalesDeduction::whereHas('sale', fn ($query) => $query
            ->approved()->where('crop_cycle_id', $cropCycle->id))
            ->where('deduction_type', 'transport_fare')
            ->sum('amount');
        $storageCost = (float) SalesDeduction::whereHas('sale', fn ($query) => $query
            ->approved()->where('crop_cycle_id', $cropCycle->id))
            ->where('deduction_type', 'storage')
            ->sum('amount');
        $otherDeductions = max(0, $deductions - $transportCost - $storageCost);

        $costOfProduction = $directExpenses + $labourCost + $inventoryCost + $deductions;
        $harvests = Harvest::approved()->where('crop_cycle_id', $cropCycle->id)->get();
        $actualYield = (float) $harvests->sum('total_quantity');
        $expectedYield = (float) ($cropCycle->expected_yield ?: $cropCycle->expected_yield_kg ?: $harvests->sum('expected_quantity'));
        $yieldEfficiency = $expectedYield > 0 ? ($actualYield / $expectedYield) * 100 : 0;
        $netProfit = $netSales - $costOfProduction;
        $profitMargin = $netSales > 0 ? ($netProfit / $netSales) * 100 : 0;
        $roi = $costOfProduction > 0 ? ($netProfit / $costOfProduction) * 100 : 0;
        $area = (float) ($cropCycle->land_area ?? 0);
        $areaInAcres = match (strtolower((string) ($cropCycle->land_area_unit ?? 'acres'))) {
            'hectare', 'hectares', 'ha' => $area * 2.47105,
            default => $area,
        };
        $numberOfBeds = count($cropCycle->bed_ids ?? []);

        return [
            'total_expenses' => $directExpenses,
            'input_cost' => $inputExpenses,
            'labour_cost' => $labourCost,
            'inventory_cost' => $inventoryCost,
            'transport_cost' => $transportCost,
            'storage_cost' => $storageCost,
            'miscellaneous_cost' => max(0, $directExpenses - $inputExpenses) + $otherDeductions,
            'cost_of_production' => $costOfProduction,
            'gross_sales' => $grossSales,
            'deductions_amount' => $deductions,
            'net_sales' => $netSales,
            'total_revenue' => $netSales,
            'gross_profit' => $grossSales - $costOfProduction,
            'net_profit' => $netProfit,
            'profit_margin' => $profitMargin,
            'roi_percentage' => $roi,
            'expected_yield' => $expectedYield,
            'actual_yield' => $actualYield,
            'yield_efficiency' => $yieldEfficiency,
            'yield_unit' => $cropCycle->yield_unit ?: 'kg',
            'profit_per_kg' => $actualYield > 0 ? $netProfit / $actualYield : 0,
            'profit_per_bed' => $numberOfBeds > 0 ? $netProfit / $numberOfBeds : 0,
            'cost_per_kg' => $actualYield > 0 ? $costOfProduction / $actualYield : 0,
            'revenue_per_kg' => $actualYield > 0 ? $netSales / $actualYield : 0,
            'profit_per_acre' => $areaInAcres > 0 ? $netProfit / $areaInAcres : 0,
            'total_area' => $area,
            'area_unit' => $cropCycle->land_area_unit ?: 'acres',
            'number_of_beds' => $numberOfBeds,
            'average_selling_price' => $averagePrice,
            'first_harvest_date' => $harvests->min('harvest_date'),
            'last_harvest_date' => $harvests->max('harvest_date'),
            'cycle_start_date' => $cropCycle->start_date,
            'cycle_end_date' => $cropCycle->completed_at ?: $cropCycle->expected_harvest_date,
            'crop_duration_days' => $cropCycle->start_date
                ? (int) floor($cropCycle->start_date->diffInDays($cropCycle->completed_at ?: now()))
                : 0,
            'cost_breakdown' => [
                'inputs' => $inputExpenses,
                'other_expenses' => max(0, $directExpenses - $inputExpenses),
                'labour' => $labourCost,
                'inventory' => $inventoryCost,
                'transport' => $transportCost,
                'storage' => $storageCost,
                'other_deductions' => $otherDeductions,
            ],
            'revenue_breakdown' => [
                'gross_sales' => $grossSales,
                'deductions' => $deductions,
                'net_sales' => $netSales,
            ],
        ];
    }

    public function calculateSeasonHealthScore(): float
    {
        $yield = min(max((float) $this->yield_efficiency, 0), 100);
        $margin = min(max(((float) $this->profit_margin / 40) * 100, 0), 100);
        $cost = min(max((float) $this->cost_efficiency_score, 0), 100);
        $roi = min(max((float) $this->roi_percentage, 0), 100);

        return round(($yield * 0.30) + ($margin * 0.40) + ($cost * 0.20) + ($roi * 0.10), 2);
    }

    public function generateInsights(): array
    {
        $insights = [];
        if ((float) $this->labour_cost_percentage > 40) {
            $insights[] = ['type' => 'cost_alert', 'priority' => 'medium', 'message' => 'Labour is a high share of production cost.'];
        }
        if ((float) $this->yield_efficiency < 70) {
            $insights[] = ['type' => 'yield_performance', 'priority' => 'high', 'message' => 'Yield is below 70% of the planned target.'];
        }
        if ((float) $this->net_profit < 0) {
            $insights[] = ['type' => 'profit_warning', 'priority' => 'high', 'message' => 'This crop cycle is currently operating at a loss.'];
        }

        return $insights;
    }

    public function scopeForCropCycle($query, string $cropCycleId)
    {
        return $query->where('crop_cycle_id', $cropCycleId);
    }

    public function scopeForFarm($query, string $farmId)
    {
        return $query->where('farm_id', $farmId);
    }

    public function scopeLatest($query)
    {
        return $query->orderByDesc('calculated_at');
    }

    public static function calculateForCropCycle(string $cropCycleId): self
    {
        $cropCycle = CropCycle::findOrFail($cropCycleId);
        $snapshot = static::forCropCycle($cropCycleId)->latest()->first()
            ?? new static(['crop_cycle_id' => $cropCycleId, 'farm_id' => $cropCycle->farm_id]);
        $snapshot->setRelation('cropCycle', $cropCycle);
        $data = $snapshot->calculateProfitability();
        $snapshot->fill($data);

        $totalCosts = (float) $data['cost_of_production'];
        $percentage = fn (float $amount): float => $totalCosts > 0 ? round(($amount / $totalCosts) * 100, 2) : 0;
        $snapshot->labour_cost_percentage = $percentage((float) $data['labour_cost']);
        $snapshot->input_cost_percentage = $percentage((float) $data['input_cost']);
        $snapshot->inventory_cost_percentage = $percentage((float) $data['inventory_cost']);
        $snapshot->transport_cost_percentage = $percentage((float) $data['transport_cost']);
        $snapshot->other_cost_percentage = max(0, round(100 - $snapshot->labour_cost_percentage
            - $snapshot->input_cost_percentage - $snapshot->inventory_cost_percentage
            - $snapshot->transport_cost_percentage, 2));
        $snapshot->cost_efficiency_score = $totalCosts > 0
            ? min(100, max(0, ((float) $data['total_revenue'] / $totalCosts) * 50))
            : ((float) $data['total_revenue'] > 0 ? 100 : 0);
        $snapshot->yield_performance_score = min(100, max(0, (float) $data['yield_efficiency']));
        $snapshot->efficiency_score = round(($snapshot->cost_efficiency_score + $snapshot->yield_performance_score) / 2, 2);
        $snapshot->season_health_score = $snapshot->calculateSeasonHealthScore();
        $snapshot->insights = json_encode($snapshot->generateInsights());
        $snapshot->calculation_status = 'completed';
        $snapshot->calculated_at = now();
        $snapshot->save();

        event(new ProfitabilityCalculated($snapshot));

        return $snapshot->fresh('cropCycle');
    }
}
