<?php

namespace App\Services;

use App\Models\BedCropAssignment;
use App\Models\BedNote;
use App\Models\Expense;
use App\Models\FieldObservation;
use App\Models\LabourEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BedReportService
{
    public function build(Collection $beds, ?Carbon $dateFrom, int $timelineLimit = 500): array
    {
        $bedIds = $beds->pluck('id');
        $bedNames = $beds->pluck('id', 'name');

        $assignments = BedCropAssignment::query()
            ->whereIn('bed_id', $bedIds)
            ->when($dateFrom, fn ($query) => $query->where('start_date', '>=', $dateFrom->toDateString()))
            ->orderByDesc('start_date')
            ->get();

        $labour = Schema::hasTable('labour_entries')
            ? LabourEntry::query()
                ->whereIn('bed_id', $bedIds)
                ->where('status', 'approved')
                ->when($dateFrom, fn ($query) => $query->where('labour_date', '>=', $dateFrom->toDateString()))
                ->orderByDesc('labour_date')
                ->get()
            : collect();

        $expenses = Schema::hasTable('expenses')
            ? Expense::query()
                ->with('category:id,name,slug')
                ->whereIn('bed_id', $bedIds)
                ->where('status', 'approved')
                ->where('is_deleted', false)
                ->when($dateFrom, fn ($query) => $query->where('expense_date', '>=', $dateFrom->toDateString()))
                ->orderByDesc('expense_date')
                ->get()
            : collect();

        $stockMovements = $this->stockMovements($bedIds, $dateFrom);
        $harvests = $this->harvests($bedIds, $bedNames, $dateFrom);
        $sales = $this->sales($harvests);

        $observations = Schema::hasTable('field_observations')
            ? FieldObservation::query()
                ->whereIn('bed_id', $bedIds)
                ->where('status', '!=', 'rejected')
                ->when($dateFrom, fn ($query) => $query->where('observation_date', '>=', $dateFrom->toDateString()))
                ->orderByDesc('observation_date')
                ->get()
            : collect();

        $notes = Schema::hasTable('bed_notes')
            ? BedNote::query()
                ->whereIn('bed_id', $bedIds)
                ->where('status', '!=', 'rejected')
                ->when($dateFrom, fn ($query) => $query->where('note_date', '>=', $dateFrom->toDateString()))
                ->orderByDesc('note_date')
                ->get()
            : collect();

        $timeline = $this->timeline(
            $assignments,
            $labour,
            $expenses,
            $stockMovements,
            $harvests,
            $sales,
            $observations,
            $notes,
            $timelineLimit + 1
        );
        $timelineTruncated = $timeline->count() > $timelineLimit;
        $timeline = $timeline->take($timelineLimit)->values();

        $summary = $this->summary(
            $assignments,
            $labour,
            $expenses,
            $stockMovements,
            $harvests,
            $sales,
            $observations,
            $notes
        );

        return [
            'beds' => $beds->map(fn ($bed) => [
                'id' => $bed->id,
                'name' => $bed->name,
                'bed_type' => $bed->bed_type,
                'status' => $bed->status,
                'size_area' => (float) ($bed->size_area ?? 0),
                'area_unit' => $bed->area_unit,
                'health_score' => (float) $bed->health_score,
            ])->values(),
            'summary' => $summary,
            'cost_breakdown' => [
                'labour' => $summary['total_labour_cost'],
                'expenses' => $summary['total_expense_cost'],
                'inventory_inputs' => $summary['total_input_cost'],
                'total' => $summary['total_cost'],
            ],
            'crop_performance' => $this->cropPerformance($assignments, $harvests, $sales),
            'season_performance' => $this->seasonPerformance($assignments, $harvests, $sales),
            'timeline' => $timeline,
            'timeline_truncated' => $timelineTruncated,
            'recommendations' => $this->recommendations($summary),
        ];
    }

    private function stockMovements(Collection $bedIds, ?Carbon $dateFrom): Collection
    {
        if (!Schema::hasTable('stock_movements')) {
            return collect();
        }

        $dateColumn = 'movement_date';

        return DB::table('stock_movements as movement')
            ->leftJoin('inventory_items as item', 'item.id', '=', 'movement.inventory_item_id')
            ->whereIn('movement.bed_id', $bedIds)
            ->where('movement.movement_type', 'out')
            ->when($dateFrom, fn ($query) => $query->where("movement.{$dateColumn}", '>=', $dateFrom->toDateString()))
            ->select([
                'movement.id',
                'movement.bed_id',
                'movement.crop_cycle_id',
                "movement.{$dateColumn} as movement_date",
                'movement.quantity',
                'movement.cost_per_unit',
                'movement.notes',
                'item.name as item_name',
                'item.unit',
                'item.average_cost',
                'item.cost_per_unit as item_cost_per_unit',
            ])
            ->orderByDesc("movement.{$dateColumn}")
            ->get()
            ->map(function ($movement) {
                $unitCost = (float) ($movement->cost_per_unit
                    ?? $movement->average_cost
                    ?? $movement->item_cost_per_unit
                    ?? 0);
                $movement->total_cost = (float) $movement->quantity * $unitCost;
                return $movement;
            });
    }

    private function harvests(Collection $bedIds, Collection $bedNames, ?Carbon $dateFrom): Collection
    {
        if (!Schema::hasTable('harvest_beds') || !Schema::hasTable('harvests')) {
            return collect();
        }

        $query = DB::table('harvest_beds as harvest_bed')
            ->join('harvests as harvest', 'harvest.id', '=', 'harvest_bed.harvest_id')
            ->where('harvest.status', 'approved')
            ->when($dateFrom, fn ($builder) => $builder->where('harvest.harvest_date', '>=', $dateFrom->toDateString()));

        if (Schema::hasColumn('harvest_beds', 'bed_id')) {
            $query->whereIn('harvest_bed.bed_id', $bedIds);
        } else {
            $query->whereIn('harvest_bed.bed_name', $bedNames->keys());
        }

        return $query->select([
            'harvest_bed.id',
            'harvest_bed.harvest_id',
            Schema::hasColumn('harvest_beds', 'bed_id')
                ? 'harvest_bed.bed_id'
                : DB::raw('NULL as bed_id'),
            'harvest_bed.bed_name',
            'harvest_bed.quantity',
            'harvest_bed.unit',
            'harvest_bed.grade',
            'harvest_bed.area_harvested',
            'harvest_bed.yield_per_sqm',
            'harvest_bed.bed_notes',
            'harvest.crop_cycle_id',
            'harvest.harvest_date',
            'harvest.total_quantity as harvest_total_quantity',
            'harvest.notes as harvest_notes',
        ])
            ->orderByDesc('harvest.harvest_date')
            ->get()
            ->map(function ($harvest) use ($bedNames) {
                if (!$harvest->bed_id) {
                    $harvest->bed_id = $bedNames->get($harvest->bed_name);
                }
                return $harvest;
            });
    }

    private function sales(Collection $harvests): Collection
    {
        if ($harvests->isEmpty() || !Schema::hasTable('sales')) {
            return collect();
        }

        $harvestIds = $harvests->pluck('harvest_id')->unique();
        $salesByHarvest = DB::table('sales')
            ->whereIn('harvest_id', $harvestIds)
            ->where('status', 'approved')
            ->get()
            ->groupBy('harvest_id');

        return $harvests->flatMap(function ($harvest) use ($salesByHarvest) {
            return $salesByHarvest->get($harvest->harvest_id, collect())->map(function ($sale) use ($harvest) {
                $share = (float) $harvest->harvest_total_quantity > 0
                    ? (float) $harvest->quantity / (float) $harvest->harvest_total_quantity
                    : 0;

                return (object) [
                    'id' => "{$sale->id}:{$harvest->bed_id}",
                    'sale_id' => $sale->id,
                    'harvest_id' => $harvest->harvest_id,
                    'bed_id' => $harvest->bed_id,
                    'crop_cycle_id' => $harvest->crop_cycle_id,
                    'sale_date' => $sale->sale_date,
                    'buyer_name' => $sale->buyer_name,
                    'quantity_sold' => (float) $sale->quantity_sold * $share,
                    'unit' => $sale->unit,
                    'net_income' => (float) $sale->net_income * $share,
                    'gross_income' => (float) $sale->gross_income * $share,
                    'payment_status' => $sale->payment_status,
                    'notes' => $sale->notes,
                ];
            });
        })->values();
    }

    private function summary(
        Collection $assignments,
        Collection $labour,
        Collection $expenses,
        Collection $stockMovements,
        Collection $harvests,
        Collection $sales,
        Collection $observations,
        Collection $notes
    ): array {
        $labourCost = $labour->sum(fn ($entry) => $entry->payment_type === 'piece_rate' && $entry->units
            ? (float) $entry->amount * $entry->units
            : (float) $entry->amount);
        $expenseCost = $expenses
            ->reject(fn ($expense) => $this->isLabourExpense($expense))
            ->sum(fn ($expense) => (float) $expense->amount
                + (float) $expense->tax_amount
                + (float) $expense->transport_cost
                + (float) $expense->handling_fee);
        $inputCost = $stockMovements->sum('total_cost');
        $totalCost = $labourCost + $expenseCost + $inputCost;
        $revenue = $sales->sum('net_income');
        $profit = $revenue - $totalCost;
        $completed = $assignments->where('assignment_status', 'completed');
        $durations = $completed
            ->filter(fn ($assignment) => $assignment->start_date && $assignment->actual_end_date)
            ->map(fn ($assignment) => $assignment->start_date->diffInDays($assignment->actual_end_date));
        $yieldByUnit = $harvests
            ->groupBy(fn ($harvest) => strtolower($harvest->unit ?: 'unknown'))
            ->map(fn ($rows) => round((float) $rows->sum('quantity'), 3));
        $successfulCycles = $completed->filter(fn ($assignment) =>
            (float) $assignment->yield_efficiency_score >= 70
            || in_array($assignment->performance_rating, ['good', 'excellent'], true)
        )->count();

        return [
            'total_cycles' => $assignments->count(),
            'completed_cycles' => $completed->count(),
            'total_harvests' => $harvests->pluck('harvest_id')->unique()->count(),
            'total_yield_kg' => (float) ($yieldByUnit->get('kg', 0) + $yieldByUnit->get('kilograms', 0)),
            'yield_by_unit' => $yieldByUnit,
            'total_labour_cost' => round($labourCost, 2),
            'total_expense_cost' => round($expenseCost, 2),
            'total_input_cost' => round($inputCost, 2),
            'total_cost' => round($totalCost, 2),
            'total_revenue' => round($revenue, 2),
            'net_profit' => round($profit, 2),
            'profit_margin_percentage' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
            'roi_percentage' => $totalCost > 0 ? round(($profit / $totalCost) * 100, 2) : 0,
            'average_cycle_duration_days' => $durations->isNotEmpty() ? round($durations->avg(), 1) : 0,
            'success_rate' => $completed->isNotEmpty() ? round(($successfulCycles / $completed->count()) * 100, 1) : 0,
            'observation_count' => $observations->count() + $notes->count(),
            'open_issue_count' => $observations->where('is_resolved', false)->count()
                + $notes->where('is_resolved', false)->whereIn('severity', ['high', 'critical'])->count(),
        ];
    }

    private function timeline(
        Collection $assignments,
        Collection $labour,
        Collection $expenses,
        Collection $stockMovements,
        Collection $harvests,
        Collection $sales,
        Collection $observations,
        Collection $notes,
        int $limit
    ): Collection {
        $items = collect();

        foreach ($assignments as $assignment) {
            $items->push([
                'id' => "crop-cycle:{$assignment->id}",
                'type' => 'crop_cycle',
                'title' => trim("{$assignment->crop_name} {$assignment->variety}"),
                'status' => $assignment->assignment_status,
                'event_date' => $assignment->start_date?->toDateString(),
                'end_date' => $assignment->actual_end_date?->toDateString() ?? $assignment->expected_end_date?->toDateString(),
                'bed_id' => $assignment->bed_id,
                'crop_cycle_id' => $assignment->crop_cycle_id,
                'description' => $assignment->completion_notes,
                'details' => [
                    'expected_yield_kg' => (float) $assignment->expected_yield_kg,
                    'actual_yield_kg' => (float) $assignment->actual_yield_kg,
                    'yield_efficiency' => (float) $assignment->yield_efficiency_score,
                    'season' => $assignment->season,
                    'performance_rating' => $assignment->performance_rating,
                ],
            ]);
        }

        foreach ($labour as $entry) {
            $amount = $entry->payment_type === 'piece_rate' && $entry->units
                ? (float) $entry->amount * $entry->units
                : (float) $entry->amount;
            $items->push([
                'id' => "labour:{$entry->id}",
                'type' => 'labour',
                'title' => ucwords(str_replace('_', ' ', $entry->labour_type)),
                'status' => $entry->payment_status,
                'event_date' => $entry->labour_date?->toDateString(),
                'bed_id' => $entry->bed_id,
                'crop_cycle_id' => $entry->crop_cycle_id,
                'description' => $entry->description ?: "Work by {$entry->worker_name}",
                'details' => ['amount' => $amount, 'worker_name' => $entry->worker_name],
            ]);
        }

        foreach ($expenses as $expense) {
            $items->push([
                'id' => "expense:{$expense->id}",
                'type' => 'expense',
                'title' => $expense->category?->name ?: 'Expense',
                'status' => $expense->status,
                'event_date' => $expense->expense_date?->toDateString(),
                'bed_id' => $expense->bed_id,
                'crop_cycle_id' => $expense->crop_cycle_id,
                'description' => $expense->description,
                'details' => ['amount' => (float) $expense->total_amount],
            ]);
        }

        foreach ($stockMovements as $movement) {
            $items->push([
                'id' => "input:{$movement->id}",
                'type' => 'input',
                'title' => $movement->item_name ?: 'Inventory input',
                'status' => 'used',
                'event_date' => (string) $movement->movement_date,
                'bed_id' => $movement->bed_id,
                'crop_cycle_id' => $movement->crop_cycle_id,
                'description' => $movement->notes,
                'details' => [
                    'amount' => round($movement->total_cost, 2),
                    'quantity' => (float) $movement->quantity,
                    'unit' => $movement->unit,
                ],
            ]);
        }

        foreach ($harvests as $harvest) {
            $items->push([
                'id' => "harvest:{$harvest->id}",
                'type' => 'harvest',
                'title' => 'Harvest recorded',
                'status' => 'approved',
                'event_date' => (string) $harvest->harvest_date,
                'bed_id' => $harvest->bed_id,
                'crop_cycle_id' => $harvest->crop_cycle_id,
                'description' => $harvest->bed_notes ?: $harvest->harvest_notes,
                'details' => [
                    'quantity' => (float) $harvest->quantity,
                    'unit' => $harvest->unit,
                    'grade' => $harvest->grade,
                    'yield_per_sqm' => (float) ($harvest->yield_per_sqm ?? 0),
                ],
            ]);
        }

        foreach ($sales as $sale) {
            $items->push([
                'id' => "sale:{$sale->id}",
                'type' => 'sale',
                'title' => $sale->buyer_name ? "Sale to {$sale->buyer_name}" : 'Produce sale',
                'status' => $sale->payment_status,
                'event_date' => (string) $sale->sale_date,
                'bed_id' => $sale->bed_id,
                'crop_cycle_id' => $sale->crop_cycle_id,
                'description' => $sale->notes,
                'details' => [
                    'amount' => round($sale->net_income, 2),
                    'quantity' => round($sale->quantity_sold, 3),
                    'unit' => $sale->unit,
                ],
            ]);
        }

        foreach ($observations as $observation) {
            $items->push([
                'id' => "observation:{$observation->id}",
                'type' => 'observation',
                'title' => ucwords(str_replace('_', ' ', $observation->observation_type)),
                'status' => $observation->is_resolved ? 'resolved' : $observation->status,
                'event_date' => $observation->observation_date?->toDateString(),
                'bed_id' => $observation->bed_id,
                'crop_cycle_id' => $observation->crop_cycle_id,
                'description' => $observation->description,
                'severity' => $observation->severity,
                'details' => [
                    'is_critical' => (bool) $observation->is_critical,
                    'requires_immediate_action' => (bool) $observation->requires_immediate_action,
                ],
            ]);
        }

        foreach ($notes as $note) {
            $items->push([
                'id' => "note:{$note->id}",
                'type' => 'note',
                'title' => ucwords(str_replace('_', ' ', $note->note_type)),
                'status' => $note->is_resolved ? 'resolved' : $note->status,
                'event_date' => $note->note_date?->toDateString(),
                'bed_id' => $note->bed_id,
                'crop_cycle_id' => $note->crop_cycle_id,
                'description' => $note->note_content,
                'severity' => $note->severity,
                'details' => ['requires_action' => (bool) $note->requires_action],
            ]);
        }

        return $items
            ->filter(fn ($item) => !empty($item['event_date']))
            ->sortByDesc(fn ($item) => "{$item['event_date']}:{$item['id']}")
            ->take($limit)
            ->values();
    }

    private function cropPerformance(Collection $assignments, Collection $harvests, Collection $sales): Collection
    {
        return $assignments->groupBy('crop_name')->map(function ($cycles, $cropName) use ($harvests, $sales) {
            $cycleIds = $cycles->pluck('crop_cycle_id');
            $cropHarvests = $harvests->whereIn('crop_cycle_id', $cycleIds);
            $cropSales = $sales->whereIn('crop_cycle_id', $cycleIds);

            return [
                'crop_name' => $cropName ?: 'Unknown crop',
                'cycles' => $cycles->count(),
                'yield_kg' => (float) $cropHarvests
                    ->filter(fn ($row) => in_array(strtolower($row->unit), ['kg', 'kilograms'], true))
                    ->sum('quantity'),
                'revenue' => round((float) $cropSales->sum('net_income'), 2),
                'average_efficiency' => round((float) $cycles->avg('yield_efficiency_score'), 1),
            ];
        })->values();
    }

    private function seasonPerformance(Collection $assignments, Collection $harvests, Collection $sales): Collection
    {
        return $assignments->groupBy(fn ($assignment) => $assignment->season ?: 'unknown')
            ->map(function ($cycles, $season) use ($harvests, $sales) {
                $cycleIds = $cycles->pluck('crop_cycle_id');
                return [
                    'season' => $season,
                    'cycles' => $cycles->count(),
                    'yield_kg' => (float) $harvests
                        ->whereIn('crop_cycle_id', $cycleIds)
                        ->filter(fn ($row) => in_array(strtolower($row->unit), ['kg', 'kilograms'], true))
                        ->sum('quantity'),
                    'revenue' => round((float) $sales->whereIn('crop_cycle_id', $cycleIds)->sum('net_income'), 2),
                    'average_efficiency' => round((float) $cycles->avg('yield_efficiency_score'), 1),
                ];
            })->values();
    }

    private function recommendations(array $summary): array
    {
        $recommendations = [];

        if ($summary['open_issue_count'] > 0) {
            $recommendations[] = [
                'type' => 'bed_health',
                'priority' => 'high',
                'title' => 'Resolve open bed issues',
                'description' => "{$summary['open_issue_count']} unresolved high-impact observations require follow-up.",
            ];
        }

        if ($summary['total_revenue'] > 0 && $summary['net_profit'] < 0) {
            $recommendations[] = [
                'type' => 'cost_control',
                'priority' => 'high',
                'title' => 'Review bed production costs',
                'description' => 'Recorded costs exceed bed-attributed sales revenue for this period.',
            ];
        }

        if ($summary['completed_cycles'] > 0 && $summary['success_rate'] < 70) {
            $recommendations[] = [
                'type' => 'yield',
                'priority' => 'medium',
                'title' => 'Review low-yield crop cycles',
                'description' => 'Completed cycles are below the target success rate.',
            ];
        }

        return $recommendations;
    }

    private function isLabourExpense(Expense $expense): bool
    {
        $category = strtolower((string) ($expense->category?->slug ?: $expense->category?->name));

        return str_contains($category, 'labour')
            || str_contains($category, 'labor')
            || str_contains($category, 'worker-wage');
    }
}
