<?php

namespace App\Services;

use App\Models\Farm;
use App\Models\CropCycle;
use App\Models\Expense;
use App\Models\LabourEntry;
use App\Models\InventoryItem;
use Illuminate\Support\Facades\Log;
use Exception;

class EcosystemEventHandler
{
    protected $kafkaProducer;

    public function __construct(KafkaProducerService $kafkaProducer)
    {
        $this->kafkaProducer = $kafkaProducer;
    }

    /**
     * Handle farm registration event
     */
    public function handleFarmRegistration(Farm $farm): void
    {
        try {
            $farmData = [
                'id' => $farm->id,
                'name' => $farm->name,
                'county' => $farm->county,
                'ward' => $farm->ward,
                'farm_type' => $farm->farm_type,
                'size' => $farm->size,
                'registered_at' => $farm->created_at->toISOString(),
            ];

            $this->kafkaProducer->publishFarmRegistration($farmData);

            Log::info('Farm registration event published to ecosystem', [
                'farm_id' => $farm->id,
                'farm_name' => $farm->name
            ]);
        } catch (Exception $e) {
            Log::error('Failed to publish farm registration event', [
                'farm_id' => $farm->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle crop planting event
     */
    public function handleCropPlanting(CropCycle $cropCycle): void
    {
        try {
            $cropData = [
                'id' => $cropCycle->id,
                'crop_name' => $cropCycle->crop_name,
                'variety' => $cropCycle->variety,
                'planting_date' => $cropCycle->planting_date,
                'expected_harvest_date' => $cropCycle->expected_harvest_date,
                'area' => $cropCycle->area,
                'farm_id' => $cropCycle->farm_id,
            ];

            $this->kafkaProducer->publishCropPlanting($cropData);

            Log::info('Crop planting event published to ecosystem', [
                'crop_cycle_id' => $cropCycle->id,
                'crop_name' => $cropCycle->crop_name
            ]);
        } catch (Exception $e) {
            Log::error('Failed to publish crop planting event', [
                'crop_cycle_id' => $cropCycle->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle harvest completion event
     */
    public function handleHarvestCompletion(CropCycle $cropCycle): void
    {
        try {
            $harvestData = [
                'crop_cycle_id' => $cropCycle->id,
                'harvest_date' => $cropCycle->harvest_date,
                'yield_quantity' => $cropCycle->yield_quantity,
                'yield_unit' => $cropCycle->yield_unit,
                'quality_grade' => $cropCycle->quality_grade,
                'farm_id' => $cropCycle->farm_id,
            ];

            $this->kafkaProducer->publishHarvestCompletion($harvestData);

            Log::info('Harvest completion event published to ecosystem', [
                'crop_cycle_id' => $cropCycle->id,
                'yield_quantity' => $cropCycle->yield_quantity
            ]);
        } catch (Exception $e) {
            Log::error('Failed to publish harvest completion event', [
                'crop_cycle_id' => $cropCycle->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle expense recording event
     */
    public function handleExpenseRecording(Expense $expense): void
    {
        try {
            $expenseData = [
                'id' => $expense->id,
                'category' => $expense->category,
                'amount' => $expense->amount,
                'expense_date' => $expense->expense_date,
                'crop_cycle_id' => $expense->crop_cycle_id,
                'farm_id' => $expense->farm_id,
            ];

            $this->kafkaProducer->publishExpenseRecording($expenseData);

            Log::info('Expense recording event published to ecosystem', [
                'expense_id' => $expense->id,
                'amount' => $expense->amount
            ]);
        } catch (Exception $e) {
            Log::error('Failed to publish expense recording event', [
                'expense_id' => $expense->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle labour tracking event
     */
    public function handleLabourTracking(LabourEntry $labourEntry): void
    {
        try {
            $labourData = [
                'id' => $labourEntry->id,
                'labour_type' => $labourEntry->labour_type,
                'amount' => $labourEntry->amount,
                'worker_name' => $labourEntry->worker_name,
                'labour_date' => $labourEntry->labour_date,
                'crop_cycle_id' => $labourEntry->crop_cycle_id,
                'farm_id' => $labourEntry->farm_id,
            ];

            $this->kafkaProducer->publishLabourTracking($labourData);

            Log::info('Labour tracking event published to ecosystem', [
                'labour_entry_id' => $labourEntry->id,
                'labour_type' => $labourEntry->labour_type
            ]);
        } catch (Exception $e) {
            Log::error('Failed to publish labour tracking event', [
                'labour_entry_id' => $labourEntry->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle inventory update event
     */
    public function handleInventoryUpdate(InventoryItem $item, string $movementType, float $quantity): void
    {
        try {
            $inventoryData = [
                'item_id' => $item->id,
                'item_name' => $item->name,
                'movement_type' => $movementType,
                'quantity' => $quantity,
                'current_quantity' => $item->current_quantity,
                'movement_date' => now()->toISOString(),
                'farm_id' => $item->farm_id,
            ];

            $this->kafkaProducer->publishInventoryUpdate($inventoryData);

            Log::info('Inventory update event published to ecosystem', [
                'item_id' => $item->id,
                'movement_type' => $movementType,
                'quantity' => $quantity
            ]);
        } catch (Exception $e) {
            Log::error('Failed to publish inventory update event', [
                'item_id' => $item->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle financial transaction event
     */
    public function handleFinancialTransaction(array $transactionData): void
    {
        try {
            $this->kafkaProducer->publish(config('kafka.topics.financial_transaction'), [
                'event_type' => 'financial_transaction',
                'transaction_id' => $transactionData['id'],
                'type' => $transactionData['type'],
                'amount' => $transactionData['amount'],
                'currency' => $transactionData['currency'] ?? 'KES',
                'transaction_date' => $transactionData['transaction_date'],
                'source_module' => $transactionData['source_module'],
                'farm_id' => $transactionData['farm_id'],
            ], $transactionData['id']);

            Log::info('Financial transaction event published to ecosystem', [
                'transaction_id' => $transactionData['id'],
                'type' => $transactionData['type']
            ]);
        } catch (Exception $e) {
            Log::error('Failed to publish financial transaction event', [
                'transaction_id' => $transactionData['id'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle harvest recording event
     */
    public function handleHarvestRecording(\App\Models\Harvest $harvest): void
    {
        try {
            $harvestData = [
                'id' => $harvest->id,
                'crop_cycle_id' => $harvest->crop_cycle_id,
                'crop_name' => $harvest->cropCycle?->crop_name,
                'harvest_date' => $harvest->harvest_date->toISOString(),
                'total_quantity' => $harvest->total_quantity,
                'unit' => $harvest->unit,
                'grade' => $harvest->getGradeValue(),
                'worker_name' => $harvest->worker?->name,
                'variance_percentage' => $harvest->variance_percentage,
                'farm_id' => $harvest->cropCycle?->farm_id,
            ];

            $this->kafkaProducer->publishHarvestRecording($harvestData);

            Log::info('Harvest recording event published to ecosystem', [
                'harvest_id' => $harvest->id,
                'crop_name' => $harvestData['crop_name'],
                'quantity' => $harvest->total_quantity
            ]);
        } catch (Exception $e) {
            Log::error('Failed to publish harvest recording event', [
                'harvest_id' => $harvest->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle harvest sales allocation event
     */
    public function handleHarvestSalesAllocation(\App\Models\HarvestSalesAllocation $allocation): void
    {
        try {
            $allocationData = [
                'id' => $allocation->id,
                'harvest_id' => $allocation->harvest_id,
                'allocation_type' => $allocation->allocation_type,
                'allocated_quantity' => $allocation->allocated_quantity,
                'destination' => $allocation->destination,
                'price_per_unit' => $allocation->price_per_unit,
                'total_value' => $allocation->total_value,
                'status' => $allocation->status,
                'allocation_date' => $allocation->allocation_date->toISOString(),
            ];

            $this->kafkaProducer->publishHarvestSalesAllocation($allocationData);

            Log::info('Harvest sales allocation event published to ecosystem', [
                'allocation_id' => $allocation->id,
                'allocation_type' => $allocation->allocation_type,
                'quantity' => $allocation->allocated_quantity
            ]);
        } catch (Exception $e) {
            Log::error('Failed to publish harvest sales allocation event', [
                'allocation_id' => $allocation->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle harvest quality report event
     */
    public function handleHarvestQualityReport(string $cropCycleId, array $qualityData): void
    {
        try {
            $reportData = [
                'harvest_id' => $qualityData['harvest_id'] ?? null,
                'crop_cycle_id' => $cropCycleId,
                'grade_distribution' => $qualityData['grade_distribution'] ?? [],
                'quality_issues' => $qualityData['quality_issues'] ?? [],
                'improvement_suggestions' => $qualityData['improvement_suggestions'] ?? [],
                'overall_rating' => $qualityData['overall_rating'] ?? null,
                'report_date' => now()->toISOString(),
            ];

            $this->kafkaProducer->publishHarvestQualityReport($reportData);

            Log::info('Harvest quality report event published to ecosystem', [
                'crop_cycle_id' => $cropCycleId,
                'overall_rating' => $reportData['overall_rating']
            ]);
        } catch (Exception $e) {
            Log::error('Failed to publish harvest quality report event', [
                'crop_cycle_id' => $cropCycleId,
                'error' => $e->getMessage()
            ]);
        }
    }
}