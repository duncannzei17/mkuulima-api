<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Http;
use App\Services\EcosystemEventHandler;
use App\Services\PlatformIntegrationService;

class EcosystemServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(EcosystemEventHandler::class);
        $this->app->singleton(PlatformIntegrationService::class);
        
        $this->app->bind('ecosystem.handler', EcosystemEventHandler::class);
        $this->app->bind('platform.integration', PlatformIntegrationService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->configureEcosystemDefaults();
        $this->registerEventListeners();
    }

    /**
     * Configure ecosystem default settings
     */
    protected function configureEcosystemDefaults(): void
    {
        config([
            'ecosystem.platform' => env('ECOSYSTEM_PLATFORM', 'farmos'),
            'ecosystem.api_key' => env('ECOSYSTEM_API_KEY'),
            'ecosystem.platforms' => [
                'venmas' => env('VENMAS_API_URL', 'http://localhost:8001/api'),
                'sikumpya' => env('SIKUMPYA_API_URL', 'http://localhost:8002/api'),
                'rentizen' => env('RENTIZEN_API_URL', 'http://localhost:8003/api'),
                'bms' => env('BMS_API_URL', 'http://localhost:8004/api'),
            ],
            'ecosystem.timeout' => 30,
            'ecosystem.retry_attempts' => 3,
        ]);
    }

    /**
     * Register ecosystem event listeners
     */
    protected function registerEventListeners(): void
    {
        // Register event listeners for cross-platform communication
        // This will be expanded based on specific ecosystem requirements
    }
}