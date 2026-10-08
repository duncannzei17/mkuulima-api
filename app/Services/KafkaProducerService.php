<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class KafkaProducerService
{
    protected $brokerHost;
    protected $brokerPort;
    protected $producer;

    public function __construct(string $brokerHost = 'localhost', int $brokerPort = 9092)
    {
        $this->brokerHost = $brokerHost;
        $this->brokerPort = $brokerPort;
    }

    /**
     * Initialize Kafka producer
     */
    protected function initProducer()
    {
        if (!$this->producer && extension_loaded('rdkafka')) {
            $conf = new \RdKafka\Conf();
            $conf->set('bootstrap.servers', "{$this->brokerHost}:{$this->brokerPort}");
            $conf->set('acks', 'all');
            $conf->set('retries', 3);
            $conf->set('batch.num.messages', 1000);

            $this->producer = new \RdKafka\Producer($conf);
        }
    }

    /**
     * Publish message to Kafka topic
     */
    public function publish(string $topic, array $message, ?string $key = null): bool
    {
        try {
            if (!env('KAFKA_ENABLED', false)) {
                Log::info("Kafka disabled - would publish to topic: {$topic}", $message);
                return true;
            }

            $this->initProducer();
            
            if (!$this->producer) {
                Log::warning('Kafka producer not available - rdkafka extension required');
                return false;
            }

            $topicProducer = $this->producer->newTopic($topic);
            
            $payload = json_encode([
                'timestamp' => now()->toISOString(),
                'source' => 'farmos',
                'data' => $message
            ]);

            $topicProducer->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key);
            $this->producer->flush(1000);

            Log::info("Message published to Kafka topic: {$topic}", [
                'key' => $key,
                'payload_size' => strlen($payload)
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('Failed to publish to Kafka', [
                'topic' => $topic,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Publish farm registration event
     */
    public function publishFarmRegistration(array $farmData): bool
    {
        return $this->publish(config('kafka.topics.farm_registration'), [
            'event_type' => 'farm_registration',
            'farm_id' => $farmData['id'],
            'farm_name' => $farmData['name'],
            'location' => [
                'county' => $farmData['county'] ?? null,
                'ward' => $farmData['ward'] ?? null,
            ],
            'farm_type' => $farmData['farm_type'] ?? null,
            'size' => $farmData['size'] ?? null,
        ], $farmData['id']);
    }

    /**
     * Publish crop planting event
     */
    public function publishCropPlanting(array $cropData): bool
    {
        return $this->publish(config('kafka.topics.crop_planting'), [
            'event_type' => 'crop_planting',
            'crop_cycle_id' => $cropData['id'],
            'crop_name' => $cropData['crop_name'],
            'variety' => $cropData['variety'] ?? null,
            'planting_date' => $cropData['planting_date'],
            'expected_harvest_date' => $cropData['expected_harvest_date'] ?? null,
            'area' => $cropData['area'] ?? null,
        ], $cropData['id']);
    }

    /**
     * Publish harvest completion event
     */
    public function publishHarvestCompletion(array $harvestData): bool
    {
        return $this->publish(config('kafka.topics.harvest_completion'), [
            'event_type' => 'harvest_completion',
            'crop_cycle_id' => $harvestData['crop_cycle_id'],
            'harvest_date' => $harvestData['harvest_date'],
            'yield_quantity' => $harvestData['yield_quantity'] ?? null,
            'yield_unit' => $harvestData['yield_unit'] ?? null,
            'quality_grade' => $harvestData['quality_grade'] ?? null,
        ], $harvestData['crop_cycle_id']);
    }

    /**
     * Publish expense recording event
     */
    public function publishExpenseRecording(array $expenseData): bool
    {
        return $this->publish(config('kafka.topics.expense_recording'), [
            'event_type' => 'expense_recording',
            'expense_id' => $expenseData['id'],
            'category' => $expenseData['category'],
            'amount' => $expenseData['amount'],
            'expense_date' => $expenseData['expense_date'],
            'crop_cycle_id' => $expenseData['crop_cycle_id'] ?? null,
        ], $expenseData['id']);
    }

    /**
     * Publish labour tracking event
     */
    public function publishLabourTracking(array $labourData): bool
    {
        return $this->publish(config('kafka.topics.labour_tracking'), [
            'event_type' => 'labour_tracking',
            'labour_entry_id' => $labourData['id'],
            'labour_type' => $labourData['labour_type'],
            'amount' => $labourData['amount'],
            'worker_name' => $labourData['worker_name'],
            'labour_date' => $labourData['labour_date'],
            'crop_cycle_id' => $labourData['crop_cycle_id'] ?? null,
        ], $labourData['id']);
    }

    /**
     * Publish inventory update event
     */
    public function publishInventoryUpdate(array $inventoryData): bool
    {
        return $this->publish(config('kafka.topics.inventory_update'), [
            'event_type' => 'inventory_update',
            'item_id' => $inventoryData['item_id'],
            'item_name' => $inventoryData['item_name'],
            'movement_type' => $inventoryData['movement_type'],
            'quantity' => $inventoryData['quantity'],
            'current_quantity' => $inventoryData['current_quantity'],
            'movement_date' => $inventoryData['movement_date'],
        ], $inventoryData['item_id']);
    }

    /**
     * Publish harvest recording event
     */
    public function publishHarvestRecording(array $harvestData): bool
    {
        return $this->publish(config('kafka.topics.harvest_recording'), [
            'event_type' => 'harvest_recording',
            'harvest_id' => $harvestData['id'],
            'crop_cycle_id' => $harvestData['crop_cycle_id'],
            'crop_name' => $harvestData['crop_name'],
            'harvest_date' => $harvestData['harvest_date'],
            'total_quantity' => $harvestData['total_quantity'],
            'unit' => $harvestData['unit'],
            'grade' => $harvestData['grade'] ?? null,
            'worker_name' => $harvestData['worker_name'] ?? null,
            'variance_percentage' => $harvestData['variance_percentage'] ?? null,
            'farm_id' => $harvestData['farm_id'],
        ], $harvestData['id']);
    }

    /**
     * Publish harvest sales allocation event
     */
    public function publishHarvestSalesAllocation(array $allocationData): bool
    {
        return $this->publish(config('kafka.topics.harvest_sales'), [
            'event_type' => 'harvest_sales_allocation',
            'allocation_id' => $allocationData['id'],
            'harvest_id' => $allocationData['harvest_id'],
            'allocation_type' => $allocationData['allocation_type'],
            'allocated_quantity' => $allocationData['allocated_quantity'],
            'destination' => $allocationData['destination'] ?? null,
            'price_per_unit' => $allocationData['price_per_unit'] ?? null,
            'total_value' => $allocationData['total_value'] ?? null,
            'status' => $allocationData['status'],
            'allocation_date' => $allocationData['allocation_date'],
        ], $allocationData['id']);
    }

    /**
     * Publish harvest quality report event
     */
    public function publishHarvestQualityReport(array $qualityData): bool
    {
        return $this->publish(config('kafka.topics.harvest_quality'), [
            'event_type' => 'harvest_quality_report',
            'harvest_id' => $qualityData['harvest_id'],
            'crop_cycle_id' => $qualityData['crop_cycle_id'],
            'grade_distribution' => $qualityData['grade_distribution'],
            'quality_issues' => $qualityData['quality_issues'] ?? [],
            'improvement_suggestions' => $qualityData['improvement_suggestions'] ?? [],
            'overall_rating' => $qualityData['overall_rating'] ?? null,
            'report_date' => $qualityData['report_date'],
        ], $qualityData['harvest_id']);
    }
}