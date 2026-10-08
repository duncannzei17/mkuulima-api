<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Exception;

class KafkaEventService
{
    private $kafkaEnabled;
    private $brokers;
    private $defaultTopic;
    private $retryAttempts;
    
    public function __construct()
    {
        $this->kafkaEnabled = config('kafka.enabled', false);
        $this->brokers = config('kafka.brokers', 'localhost:9092');
        $this->defaultTopic = config('kafka.default_topic', 'farmOS-events');
        $this->retryAttempts = config('kafka.retry_attempts', 3);
    }

    public function publishEvent(array $eventData, ?string $topic = null): bool
    {
        if (!$this->kafkaEnabled) {
            Log::info('Kafka disabled, skipping event publication', [
                'event_type' => $eventData['event_type'] ?? 'unknown',
                'topic' => $topic ?? $this->defaultTopic
            ]);
            return true;
        }

        $topic = $topic ?? $this->defaultTopic;
        $attempts = 0;

        while ($attempts < $this->retryAttempts) {
            try {
                $this->sendToKafka($topic, $eventData);
                
                Log::info('Kafka event published successfully', [
                    'event_type' => $eventData['event_type'] ?? 'unknown',
                    'event_id' => $eventData['event_id'] ?? null,
                    'topic' => $topic,
                    'attempt' => $attempts + 1
                ]);

                return true;

            } catch (Exception $e) {
                $attempts++;
                
                Log::error('Failed to publish Kafka event', [
                    'event_type' => $eventData['event_type'] ?? 'unknown',
                    'event_id' => $eventData['event_id'] ?? null,
                    'topic' => $topic,
                    'attempt' => $attempts,
                    'error' => $e->getMessage(),
                    'will_retry' => $attempts < $this->retryAttempts
                ]);

                if ($attempts >= $this->retryAttempts) {
                    return false;
                }

                // Exponential backoff
                sleep(pow(2, $attempts - 1));
            }
        }

        return false;
    }

    public function publishMarketEvent($event): bool
    {
        if (!method_exists($event, 'getKafkaPayload')) {
            Log::warning('Event does not support Kafka publishing', [
                'event_class' => get_class($event)
            ]);
            return false;
        }

        $kafkaPayload = $event->getKafkaPayload();
        
        return $this->publishEvent(
            $kafkaPayload['payload'],
            $kafkaPayload['topic'] ?? null
        );
    }

    public function publishSellOrderEvent($event): bool
    {
        if (!method_exists($event, 'getKafkaPayload')) {
            Log::warning('Event does not support Kafka publishing', [
                'event_class' => get_class($event)
            ]);
            return false;
        }

        $kafkaPayload = $event->getKafkaPayload();
        
        return $this->publishEvent(
            $kafkaPayload['payload'],
            $kafkaPayload['topic'] ?? null
        );
    }

    public function publishBuyerOfferEvent($event): bool
    {
        if (!method_exists($event, 'getKafkaPayload')) {
            Log::warning('Event does not support Kafka publishing', [
                'event_class' => get_class($event)
            ]);
            return false;
        }

        $kafkaPayload = $event->getKafkaPayload();
        
        return $this->publishEvent(
            $kafkaPayload['payload'],
            $kafkaPayload['topic'] ?? null
        );
    }

    public function publishPriceIntelligenceEvent(array $priceData, string $eventType = 'price.updated'): bool
    {
        $eventData = [
            'event_id' => uniqid('price_intel_', true),
            'timestamp' => now()->toISOString(),
            'event_type' => $eventType,
            'version' => '1.0',
            'source' => 'farmOS',
            'data' => $priceData
        ];

        return $this->publishEvent($eventData, 'market-price-intelligence');
    }

    public function publishDemandForecastEvent(array $forecastData): bool
    {
        $eventData = [
            'event_id' => uniqid('demand_forecast_', true),
            'timestamp' => now()->toISOString(),
            'event_type' => 'demand.forecast_updated',
            'version' => '1.0',
            'source' => 'farmOS',
            'data' => $forecastData
        ];

        return $this->publishEvent($eventData, 'demand-forecasting');
    }

    public function publishMarketInsightEvent(array $insightData): bool
    {
        $eventData = [
            'event_id' => uniqid('market_insight_', true),
            'timestamp' => now()->toISOString(),
            'event_type' => 'market.insight_generated',
            'version' => '1.0',
            'source' => 'farmOS',
            'data' => $insightData
        ];

        return $this->publishEvent($eventData, 'market-insights');
    }

    public function publishLogisticsEvent(array $logisticsData, string $eventType): bool
    {
        $eventData = [
            'event_id' => uniqid('logistics_', true),
            'timestamp' => now()->toISOString(),
            'event_type' => $eventType,
            'version' => '1.0',
            'source' => 'farmOS',
            'data' => $logisticsData
        ];

        return $this->publishEvent($eventData, 'logistics-ecosystem');
    }

    private function sendToKafka(string $topic, array $data): void
    {
        // This is a placeholder for actual Kafka implementation
        // In production, you would use a library like php-kafka or rdkafka
        
        if (config('app.env') === 'testing') {
            // For testing, just log the event
            Log::info('Kafka event (testing mode)', [
                'topic' => $topic,
                'data' => $data
            ]);
            return;
        }

        // Example implementation using rdkafka (requires php-rdkafka extension)
        /*
        $conf = new \RdKafka\Conf();
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('dr_msg_cb', function ($kafka, $message) {
            if ($message->err) {
                throw new Exception('Kafka delivery failed: ' . rd_kafka_err2str($message->err));
            }
        });

        $producer = new \RdKafka\Producer($conf);
        $topicConf = new \RdKafka\TopicConf();
        $kafkaTopic = $producer->newTopic($topic, $topicConf);

        $kafkaTopic->produce(RD_KAFKA_PARTITION_UA, 0, json_encode($data));
        $producer->poll(0);
        $producer->flush(10000);
        */

        // For now, simulate Kafka by logging
        Log::info('Kafka event sent', [
            'brokers' => $this->brokers,
            'topic' => $topic,
            'event_id' => $data['event_id'] ?? null,
            'event_type' => $data['event_type'] ?? null
        ]);
    }

    public function isKafkaEnabled(): bool
    {
        return $this->kafkaEnabled;
    }

    public function getKafkaConfiguration(): array
    {
        return [
            'enabled' => $this->kafkaEnabled,
            'brokers' => $this->brokers,
            'default_topic' => $this->defaultTopic,
            'retry_attempts' => $this->retryAttempts
        ];
    }
}