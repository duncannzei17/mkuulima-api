<?php

namespace App\Jobs;

use App\Models\Farm;
use App\Models\User;
use App\Models\WeatherAlert;
use App\Services\WeatherService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateWeatherAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(WeatherService $weatherService): void
    {
        Log::info('Starting automatic weather alert generation');

        try {
            // Get all farms with coordinates
            $farms = Farm::whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->with('owner')
                ->get();

            $alertsGenerated = 0;
            $farmsProcessed = 0;

            foreach ($farms as $farm) {
                try {
                    $farmsProcessed++;
                    
                    // Get current weather data
                    $currentWeather = $weatherService->getCurrentWeather(
                        $farm->latitude, 
                        $farm->longitude
                    );

                    if (empty($currentWeather)) {
                        Log::warning("No weather data available for farm: {$farm->name}", [
                            'farm_id' => $farm->id,
                            'coordinates' => [$farm->latitude, $farm->longitude]
                        ]);
                        continue;
                    }

                    // Check for weather conditions that warrant alerts
                    $alertConditions = $this->checkWeatherConditions($currentWeather, $farm);

                    foreach ($alertConditions as $condition) {
                        // Check if similar alert already exists and is active
                        $existingAlert = WeatherAlert::where('farm_id', $farm->id)
                            ->where('user_id', $farm->user_id)
                            ->where('alert_type', $condition['alert_type'])
                            ->where('status', 'active')
                            ->where('triggered_at', '>=', now()->subHours(6))
                            ->first();

                        if (!$existingAlert) {
                            WeatherAlert::create([
                                'farm_id' => $farm->id,
                                'user_id' => $farm->user_id,
                                'alert_type' => $condition['alert_type'],
                                'severity' => $condition['severity'],
                                'title' => $condition['title'],
                                'message' => $condition['message'],
                                'weather_data' => $currentWeather,
                                'conditions_met' => $condition['conditions_met'],
                                'threshold_values' => $condition['threshold_values'],
                                'recommended_actions' => $condition['recommended_actions'],
                                'triggered_at' => now(),
                                'expires_at' => now()->addHours($condition['expires_hours'] ?? 24),
                                'metadata' => [
                                    'auto_generated' => true,
                                    'weather_source' => 'openweather',
                                    'generated_at' => now()->toISOString(),
                                    'job_id' => $this->job?->uuid(),
                                ]
                            ]);

                            $alertsGenerated++;
                            
                            Log::info("Weather alert generated", [
                                'farm_id' => $farm->id,
                                'farm_name' => $farm->name,
                                'alert_type' => $condition['alert_type'],
                                'severity' => $condition['severity']
                            ]);
                        }
                    }

                } catch (\Exception $e) {
                    Log::error("Error processing weather alerts for farm: {$farm->name}", [
                        'farm_id' => $farm->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            Log::info('Weather alert generation completed', [
                'farms_processed' => $farmsProcessed,
                'alerts_generated' => $alertsGenerated
            ]);

        } catch (\Exception $e) {
            Log::error('Weather alert generation job failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e; // Re-throw to mark job as failed
        }
    }

    /**
     * Check weather conditions against thresholds and return alert conditions
     */
    protected function checkWeatherConditions(array $weatherData, Farm $farm): array
    {
        $alerts = [];

        if (!isset($weatherData['main']) || !isset($weatherData['weather'])) {
            return $alerts;
        }

        $temp = $weatherData['main']['temp'];
        $humidity = $weatherData['main']['humidity'];
        $windSpeed = $weatherData['wind']['speed'] ?? 0;
        $precipitation = $weatherData['rain']['1h'] ?? $weatherData['snow']['1h'] ?? 0;
        $mainCondition = $weatherData['weather'][0]['main'] ?? '';

        // Temperature alerts
        if ($temp > 35) {
            $alerts[] = [
                'alert_type' => 'extreme_temperature',
                'severity' => $temp > 40 ? 'critical' : 'high',
                'title' => 'High Temperature Alert',
                'message' => "Extreme high temperature detected: {$temp}°C. Take protective measures for crops and livestock.",
                'conditions_met' => ['high_temperature'],
                'threshold_values' => ['temperature_threshold' => 35, 'current_temperature' => $temp],
                'recommended_actions' => [
                    'Provide shade for livestock',
                    'Increase watering frequency',
                    'Avoid working during peak heat hours',
                    'Monitor crop stress indicators'
                ],
                'expires_hours' => 12
            ];
        } elseif ($temp < 5) {
            $alerts[] = [
                'alert_type' => 'frost',
                'severity' => $temp < 0 ? 'critical' : 'high',
                'title' => 'Frost Risk Alert',
                'message' => "Low temperature detected: {$temp}°C. Frost risk for sensitive crops.",
                'conditions_met' => ['low_temperature'],
                'threshold_values' => ['frost_threshold' => 5, 'current_temperature' => $temp],
                'recommended_actions' => [
                    'Cover sensitive plants',
                    'Use frost protection methods',
                    'Harvest ready crops',
                    'Protect water systems from freezing'
                ],
                'expires_hours' => 8
            ];
        }

        // Wind alerts
        if ($windSpeed > 10) {
            $alerts[] = [
                'alert_type' => 'strong_wind',
                'severity' => $windSpeed > 15 ? 'critical' : 'high',
                'title' => 'Strong Wind Warning',
                'message' => "High wind speeds detected: {$windSpeed} m/s. Secure farm structures and equipment.",
                'conditions_met' => ['high_wind_speed'],
                'threshold_values' => ['wind_threshold' => 10, 'current_wind_speed' => $windSpeed],
                'recommended_actions' => [
                    'Secure loose items and equipment',
                    'Check greenhouse and tunnel structures',
                    'Avoid aerial pesticide applications',
                    'Monitor tree crops for damage'
                ],
                'expires_hours' => 6
            ];
        }

        // Precipitation alerts
        if ($precipitation > 20) {
            $alerts[] = [
                'alert_type' => 'heavy_rain',
                'severity' => $precipitation > 50 ? 'critical' : 'high',
                'title' => 'Heavy Rainfall Alert',
                'message' => "Heavy rainfall detected: {$precipitation} mm/h. Monitor for flooding and soil erosion.",
                'conditions_met' => ['heavy_precipitation'],
                'threshold_values' => ['rainfall_threshold' => 20, 'current_rainfall' => $precipitation],
                'recommended_actions' => [
                    'Check drainage systems',
                    'Protect exposed soil',
                    'Monitor for waterlogging',
                    'Secure livestock in safe areas'
                ],
                'expires_hours' => 6
            ];
        }

        // Humidity alerts
        if ($humidity > 85) {
            $alerts[] = [
                'alert_type' => 'humidity',
                'severity' => 'medium',
                'title' => 'High Humidity Alert',
                'message' => "High humidity detected: {$humidity}%. Increased disease risk for crops.",
                'conditions_met' => ['high_humidity'],
                'threshold_values' => ['humidity_threshold' => 85, 'current_humidity' => $humidity],
                'recommended_actions' => [
                    'Improve ventilation in greenhouses',
                    'Monitor for fungal diseases',
                    'Adjust irrigation schedules',
                    'Apply preventive fungicides if needed'
                ],
                'expires_hours' => 12
            ];
        }

        // Storm alerts
        if (str_contains(strtolower($mainCondition), 'thunder') || 
            str_contains(strtolower($mainCondition), 'storm')) {
            $alerts[] = [
                'alert_type' => 'storm',
                'severity' => 'critical',
                'title' => 'Storm Warning',
                'message' => "Thunderstorm conditions detected. Take immediate safety precautions.",
                'conditions_met' => ['storm_conditions'],
                'threshold_values' => ['weather_condition' => $mainCondition],
                'recommended_actions' => [
                    'Move to safe shelter immediately',
                    'Avoid outdoor work',
                    'Secure all equipment and structures',
                    'Disconnect electrical equipment',
                    'Monitor livestock safety'
                ],
                'expires_hours' => 4
            ];
        }

        // Drought monitoring (based on recent rainfall patterns)
        if ($precipitation === 0 && $humidity < 30) {
            $alerts[] = [
                'alert_type' => 'drought',
                'severity' => 'medium',
                'title' => 'Dry Conditions Alert',
                'message' => "Very dry conditions detected. Monitor soil moisture and irrigation needs.",
                'conditions_met' => ['low_humidity', 'no_precipitation'],
                'threshold_values' => ['humidity_threshold' => 30, 'current_humidity' => $humidity],
                'recommended_actions' => [
                    'Monitor soil moisture levels',
                    'Adjust irrigation schedules',
                    'Apply mulching to retain moisture',
                    'Consider drought-resistant crop varieties'
                ],
                'expires_hours' => 24
            ];
        }

        return $alerts;
    }
}