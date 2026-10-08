<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Sync weather data every 30 minutes for all farms
        $schedule->command('weather:sync --all --async')
                 ->everyThirtyMinutes()
                 ->name('weather-sync-all')
                 ->description('Sync weather data for all farms')
                 ->withoutOverlapping();

        // Clear cache periodically to prevent buildup
        $schedule->command('cache:prune-stale-tags')
                 ->daily()
                 ->at('02:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}