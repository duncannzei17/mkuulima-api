<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\KafkaProducerService;
use App\Services\KafkaConsumerService;

class KafkaServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(KafkaProducerService::class, function ($app) {
            return new KafkaProducerService(
                env('KAFKA_BROKER_HOST', 'localhost'),
                env('KAFKA_BROKER_PORT', 9092)
            );
        });

        $this->app->singleton(KafkaConsumerService::class, function ($app) {
            return new KafkaConsumerService(
                env('KAFKA_BROKER_HOST', 'localhost'),
                env('KAFKA_BROKER_PORT', 9092)
            );
        });

        $this->app->bind('kafka.producer', KafkaProducerService::class);
        $this->app->bind('kafka.consumer', KafkaConsumerService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (env('KAFKA_ENABLED', false)) {
            $this->configureKafkaTopics();
        }
    }

    /**
     * Configure Kafka topics for agricultural events
     */
    protected function configureKafkaTopics(): void
    {
        config([
            'kafka.topics' => [
                'farm_registration' => 'ecosystem.farm.registration',
                'crop_planting' => 'ecosystem.farm.crop.planting',
                'harvest_completion' => 'ecosystem.farm.harvest.completion',
                'expense_recording' => 'ecosystem.farm.expense.recording',
                'labour_tracking' => 'ecosystem.farm.labour.tracking',
                'inventory_update' => 'ecosystem.farm.inventory.update',
                'market_price_update' => 'ecosystem.market.price.update',
                'weather_alert' => 'ecosystem.weather.alert',
            ],
            'kafka.consumer_groups' => [
                'farmos' => 'farmos-consumer-group',
            ]
        ]);
    }
}