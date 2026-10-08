<?php

namespace App\Console\Commands;

use App\Jobs\SyncWeatherData;
use App\Models\Farm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class SyncWeatherCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'weather:sync 
                            {--farm= : Specific farm ID to sync weather for}
                            {--coordinates= : Specific coordinates (lat,lon) to sync weather for}
                            {--all : Sync weather for all farms}
                            {--async : Run sync jobs asynchronously}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync weather data for farms';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $farmId = $this->option('farm');
        $coordinates = $this->option('coordinates');
        $all = $this->option('all');
        $async = $this->option('async');

        if ($farmId) {
            $this->info("Syncing weather for farm ID: {$farmId}");
            $this->syncWeatherForFarm($farmId, $async);
        } elseif ($coordinates) {
            $coords = explode(',', $coordinates);
            if (count($coords) !== 2) {
                $this->error('Coordinates must be in format: lat,lon');
                return 1;
            }
            $this->info("Syncing weather for coordinates: {$coordinates}");
            $this->syncWeatherForCoordinates($coords[0], $coords[1], $async);
        } elseif ($all) {
            $this->info('Syncing weather for all farms');
            $this->syncWeatherForAllFarms($async);
        } else {
            $this->error('Please specify --farm, --coordinates, or --all option');
            return 1;
        }

        return 0;
    }

    /**
     * Sync weather for a specific farm
     */
    protected function syncWeatherForFarm($farmId, $async = false)
    {
        $farm = Farm::find($farmId);
        
        if (!$farm) {
            $this->error("Farm with ID {$farmId} not found");
            return;
        }

        if (!$farm->latitude || !$farm->longitude) {
            $this->error("Farm '{$farm->name}' has no coordinates set");
            return;
        }

        if ($async) {
            SyncWeatherData::dispatch($farmId);
            $this->info("Weather sync job queued for farm '{$farm->name}'");
        } else {
            $job = new SyncWeatherData($farmId);
            $job->handle(app(\App\Services\WeatherService::class));
            $this->info("Weather synced for farm '{$farm->name}'");
        }
    }

    /**
     * Sync weather for specific coordinates
     */
    protected function syncWeatherForCoordinates($latitude, $longitude, $async = false)
    {
        if ($async) {
            SyncWeatherData::dispatch(null, $latitude, $longitude);
            $this->info("Weather sync job queued for coordinates ({$latitude}, {$longitude})");
        } else {
            $job = new SyncWeatherData(null, $latitude, $longitude);
            $job->handle(app(\App\Services\WeatherService::class));
            $this->info("Weather synced for coordinates ({$latitude}, {$longitude})");
        }
    }

    /**
     * Sync weather for all farms
     */
    protected function syncWeatherForAllFarms($async = false)
    {
        $farms = Farm::whereNotNull('latitude')->whereNotNull('longitude')->get();
        
        if ($farms->isEmpty()) {
            $this->warn('No farms with coordinates found');
            return;
        }

        $this->info("Found {$farms->count()} farms with coordinates");

        if ($async) {
            SyncWeatherData::dispatch();
            $this->info("Bulk weather sync job queued for all farms");
        } else {
            $progressBar = $this->output->createProgressBar($farms->count());
            $progressBar->start();

            $successCount = 0;
            foreach ($farms as $farm) {
                try {
                    $job = new SyncWeatherData($farm->id);
                    $job->handle(app(\App\Services\WeatherService::class));
                    $successCount++;
                } catch (\Exception $e) {
                    $this->error("\nFailed to sync weather for farm '{$farm->name}': {$e->getMessage()}");
                }
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine();
            $this->info("Weather sync completed: {$successCount}/{$farms->count()} farms synced successfully");
        }
    }
}
