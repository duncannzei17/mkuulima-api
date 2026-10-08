<?php

namespace App\Jobs;

use App\Models\Farm;
use App\Services\WeatherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncWeatherData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $farmId;
    protected $latitude;
    protected $longitude;

    /**
     * Create a new job instance.
     */
    public function __construct($farmId = null, $latitude = null, $longitude = null)
    {
        $this->farmId = $farmId;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }

    /**
     * Execute the job.
     */
    public function handle(WeatherService $weatherService)
    {
        try {
            if ($this->farmId) {
                // Sync weather for a specific farm
                $this->syncFarmWeather($this->farmId, $weatherService);
            } elseif ($this->latitude && $this->longitude) {
                // Sync weather for specific coordinates
                $this->syncCoordinateWeather($this->latitude, $this->longitude, $weatherService);
            } else {
                // Sync weather for all farms with coordinates
                $this->syncAllFarmsWeather($weatherService);
            }

            Log::info('Weather sync job completed successfully', [
                'farm_id' => $this->farmId,
                'coordinates' => $this->latitude && $this->longitude ? [$this->latitude, $this->longitude] : null
            ]);

        } catch (\Exception $e) {
            Log::error('Weather sync job failed', [
                'farm_id' => $this->farmId,
                'coordinates' => $this->latitude && $this->longitude ? [$this->latitude, $this->longitude] : null,
                'error' => $e->getMessage()
            ]);
            
            // Don't fail the job completely as weather data isn't critical
            // The system will use cached data or fallbacks
        }
    }

    /**
     * Sync weather data for a specific farm
     */
    protected function syncFarmWeather($farmId, WeatherService $weatherService)
    {
        $farm = Farm::find($farmId);
        
        if (!$farm || !$farm->latitude || !$farm->longitude) {
            Log::warning('Farm not found or missing coordinates for weather sync', ['farm_id' => $farmId]);
            return;
        }

        $this->syncCoordinateWeather($farm->latitude, $farm->longitude, $weatherService);
        
        Log::info('Weather synced for farm', [
            'farm_id' => $farm->id,
            'farm_name' => $farm->name,
            'coordinates' => [$farm->latitude, $farm->longitude]
        ]);
    }

    /**
     * Sync weather data for specific coordinates
     */
    protected function syncCoordinateWeather($latitude, $longitude, WeatherService $weatherService)
    {
        // Force refresh weather cache by clearing it first
        $weatherService->clearCache($latitude, $longitude);
        
        // Fetch fresh weather data - this will populate the cache
        $currentWeather = $weatherService->getCurrentWeather($latitude, $longitude);
        $forecast = $weatherService->getForecast($latitude, $longitude);
        
        Log::info('Weather data refreshed', [
            'coordinates' => [$latitude, $longitude],
            'temperature' => $currentWeather['temperature'] ?? 'N/A',
            'condition' => $currentWeather['condition'] ?? 'N/A'
        ]);
    }

    /**
     * Sync weather data for all farms
     */
    protected function syncAllFarmsWeather(WeatherService $weatherService)
    {
        $farms = Farm::whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->get();

        $syncedCount = 0;
        $failedCount = 0;

        foreach ($farms as $farm) {
            try {
                $this->syncCoordinateWeather($farm->latitude, $farm->longitude, $weatherService);
                $syncedCount++;
            } catch (\Exception $e) {
                $failedCount++;
                Log::warning('Failed to sync weather for farm', [
                    'farm_id' => $farm->id,
                    'farm_name' => $farm->name,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::info('Bulk weather sync completed', [
            'total_farms' => $farms->count(),
            'synced' => $syncedCount,
            'failed' => $failedCount
        ]);
    }
}