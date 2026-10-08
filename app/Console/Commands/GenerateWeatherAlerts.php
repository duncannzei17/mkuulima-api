<?php

namespace App\Console\Commands;

use App\Jobs\GenerateWeatherAlertsJob;
use Illuminate\Console\Command;

class GenerateWeatherAlerts extends Command
{
    protected $signature = 'weather:generate-alerts {--force : Force generation even if alerts already exist}';
    protected $description = 'Generate weather alerts for all farms based on current weather conditions';

    public function handle()
    {
        $this->info('Starting weather alert generation...');

        try {
            // Dispatch the job
            GenerateWeatherAlertsJob::dispatch();
            
            $this->info('Weather alert generation job dispatched successfully!');
            $this->info('Check the logs for detailed results.');

        } catch (\Exception $e) {
            $this->error('Failed to dispatch weather alert generation job: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}