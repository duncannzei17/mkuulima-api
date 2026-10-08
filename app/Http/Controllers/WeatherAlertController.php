<?php

namespace App\Http\Controllers;

use App\Models\WeatherAlert;
use App\Services\WeatherService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class WeatherAlertController extends Controller
{
    protected $weatherService;

    public function __construct(WeatherService $weatherService)
    {
        $this->weatherService = $weatherService;
    }

    /**
     * Get all weather alerts for the current farm
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $farmId = $this->farmId($request);
            
            if (!$farmId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Farm ID required'
                ], 400);
            }

            $alerts = WeatherAlert::where('farm_id', $farmId)
                ->where('user_id', $request->user()->id)
                ->with(['farm', 'user'])
                ->orderBy('triggered_at', 'desc')
                ->paginate(15);

            return response()->json([
                'success' => true,
                'data' => [
                    'alerts' => $alerts->items(),
                    'pagination' => [
                        'current_page' => $alerts->currentPage(),
                        'per_page' => $alerts->perPage(),
                        'total' => $alerts->total(),
                        'last_page' => $alerts->lastPage()
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch weather alerts', [
                'error' => $e->getMessage(),
                'farm_id' => $this->farmId($request),
                'user_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch weather alerts'
            ], 500);
        }
    }

    /**
     * Get active weather alerts for dashboard
     */
    public function active(Request $request): JsonResponse
    {
        try {
            $farmId = $this->farmId($request);
            
            if (!$farmId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Farm ID required'
                ], 400);
            }

            $alerts = WeatherAlert::where('farm_id', $farmId)
                ->where('user_id', $request->user()->id)
                ->active()
                ->notExpired()
                ->orderBy('severity', 'desc')
                ->orderBy('triggered_at', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'alerts' => $alerts,
                    'total_active' => $alerts->count(),
                    'critical_count' => $alerts->where('severity', 'critical')->count(),
                    'high_count' => $alerts->where('severity', 'high')->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch active weather alerts', [
                'error' => $e->getMessage(),
                'farm_id' => $this->farmId($request),
                'user_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch active weather alerts'
            ], 500);
        }
    }

    /**
     * Acknowledge a weather alert
     */
    public function acknowledge(Request $request, string $alertId): JsonResponse
    {
        try {
            $farmId = $this->farmId($request);
            
            $alert = WeatherAlert::where('id', $alertId)
                ->where('farm_id', $farmId)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$alert) {
                return response()->json([
                    'success' => false,
                    'message' => 'Weather alert not found'
                ], 404);
            }

            $alert->acknowledge();

            return response()->json([
                'success' => true,
                'message' => 'Weather alert acknowledged successfully',
                'data' => ['alert' => $alert->fresh()]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to acknowledge weather alert', [
                'error' => $e->getMessage(),
                'alert_id' => $alertId,
                'user_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to acknowledge weather alert'
            ], 500);
        }
    }

    /**
     * Dismiss a weather alert
     */
    public function dismiss(Request $request, string $alertId): JsonResponse
    {
        try {
            $farmId = $this->farmId($request);
            
            $alert = WeatherAlert::where('id', $alertId)
                ->where('farm_id', $farmId)
                ->where('user_id', $request->user()->id)
                ->first();

            if (!$alert) {
                return response()->json([
                    'success' => false,
                    'message' => 'Weather alert not found'
                ], 404);
            }

            $alert->dismiss();

            return response()->json([
                'success' => true,
                'message' => 'Weather alert dismissed successfully',
                'data' => ['alert' => $alert->fresh()]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to dismiss weather alert', [
                'error' => $e->getMessage(),
                'alert_id' => $alertId,
                'user_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to dismiss weather alert'
            ], 500);
        }
    }

    /**
     * Create a manual weather alert
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'alert_type' => 'required|string|in:heavy_rain,drought,extreme_temperature,strong_wind,frost,humidity,storm',
                'severity' => 'required|string|in:low,medium,high,critical',
                'title' => 'required|string|max:255',
                'message' => 'required|string',
                'recommended_actions' => 'sometimes|array',
                'expires_at' => 'sometimes|date|after:now',
                'metadata' => 'sometimes|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $farmId = $this->farmId($request);
            
            if (!$farmId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Farm ID required'
                ], 400);
            }

            $alert = WeatherAlert::create([
                'farm_id' => $farmId,
                'user_id' => $request->user()->id,
                'alert_type' => $request->alert_type,
                'severity' => $request->severity,
                'title' => $request->title,
                'message' => $request->message,
                'conditions_met' => $request->input('conditions_met', ['manual_alert' => true]),
                'recommended_actions' => $request->recommended_actions,
                'triggered_at' => now(),
                'expires_at' => $request->expires_at ? now()->parse($request->expires_at) : null,
                'metadata' => $request->metadata ?? []
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Weather alert created successfully',
                'data' => ['alert' => $alert]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create weather alert', [
                'error' => $e->getMessage(),
                'farm_id' => $this->farmId($request),
                'user_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create weather alert'
            ], 500);
        }
    }

    /**
     * Generate alerts based on current weather conditions
     */
    public function generateAlerts(Request $request): JsonResponse
    {
        try {
            $farmId = $this->farmId($request);
            $farm = $request->user()->ownedFarms()->find($farmId) ?? 
                   $request->user()->farms()->find($farmId);

            if (!$farm || !$farm->latitude || !$farm->longitude) {
                return response()->json([
                    'success' => false,
                    'message' => 'Farm coordinates not available for weather alerts'
                ], 400);
            }

            // Get current weather data
            $currentWeather = $this->weatherService->getCurrentWeather(
                $farm->latitude, 
                $farm->longitude
            );

            $generatedAlerts = [];

            // Check for various weather conditions and generate alerts
            $alertConditions = $this->checkWeatherConditions($currentWeather, $farm);

            foreach ($alertConditions as $condition) {
                // Check if similar alert already exists and is active
                $existingAlert = WeatherAlert::where('farm_id', $farmId)
                    ->where('user_id', $request->user()->id)
                    ->where('alert_type', $condition['alert_type'])
                    ->active()
                    ->where('triggered_at', '>=', now()->subHours(6))
                    ->first();

                if (!$existingAlert) {
                    $alert = WeatherAlert::create([
                        'farm_id' => $farmId,
                        'user_id' => $request->user()->id,
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
                            'generated_at' => now()->toISOString()
                        ]
                    ]);

                    $generatedAlerts[] = $alert;
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Weather alerts generated successfully',
                'data' => [
                    'generated_count' => count($generatedAlerts),
                    'alerts' => $generatedAlerts
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to generate weather alerts', [
                'error' => $e->getMessage(),
                'farm_id' => $this->farmId($request),
                'user_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate weather alerts'
            ], 500);
        }
    }

    /**
     * Check weather conditions against thresholds and return alert conditions
     */
    protected function checkWeatherConditions(array $weatherData, $farm): array
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

        return $alerts;
    }

    private function farmId(Request $request): ?string
    {
        return $request->header('X-Tenant-ID') ?: $request->header('X-Farm-ID');
    }
}
