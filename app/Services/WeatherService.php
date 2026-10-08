<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class WeatherService
{
    private $apiKey;
    private $baseUrl = 'https://api.openweathermap.org/data/2.5';
    private $cachePrefix = 'weather_';
    private $cacheDuration = 30; // minutes

    public function __construct()
    {
        $this->apiKey = config('services.openweather.api_key');
    }

    /**
     * Get current weather data for a location
     */
    public function getCurrentWeather($latitude, $longitude)
    {
        $cacheKey = $this->cachePrefix . "current_{$latitude}_{$longitude}";
        
        return Cache::remember($cacheKey, $this->cacheDuration * 60, function () use ($latitude, $longitude) {
            // If no API key is configured, return mock data for development
            if (!$this->apiKey) {
                Log::info('No OpenWeather API key configured, returning mock data');
                return $this->getMockWeatherData($latitude, $longitude);
            }

            try {
                $response = Http::get($this->baseUrl . '/weather', [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'appid' => $this->apiKey,
                    'units' => 'metric'
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    return [
                        'temperature' => round($data['main']['temp']),
                        'humidity' => $data['main']['humidity'],
                        'windSpeed' => round($data['wind']['speed'] * 3.6), // Convert m/s to km/h
                        'rainfall' => $data['rain']['1h'] ?? 0,
                        'condition' => $data['weather'][0]['description'],
                        'icon' => $data['weather'][0]['icon'],
                        'pressure' => $data['main']['pressure'],
                        'visibility' => $data['visibility'] / 1000, // Convert to km
                        'location' => [
                            'name' => $data['name'] ?? null,
                            'country' => $data['sys']['country'] ?? null,
                        ],
                        'updated_at' => now()
                    ];
                } else {
                    Log::warning('Weather API request failed', [
                        'status' => $response->status(),
                        'response' => $response->body()
                    ]);
                    return $this->getDefaultWeatherData();
                }
            } catch (\Exception $e) {
                Log::error('Weather API error', [
                    'message' => $e->getMessage(),
                    'coordinates' => [$latitude, $longitude]
                ]);
                return $this->getDefaultWeatherData();
            }
        });
    }

    /**
     * Get weather forecast for next 5 days
     */
    public function getForecast($latitude, $longitude)
    {
        $cacheKey = $this->cachePrefix . "forecast_{$latitude}_{$longitude}";
        
        return Cache::remember($cacheKey, $this->cacheDuration * 60, function () use ($latitude, $longitude) {
            // If no API key is configured, return mock data for development
            if (!$this->apiKey) {
                return $this->getMockForecastData($latitude, $longitude);
            }

            try {
                $response = Http::get($this->baseUrl . '/forecast', [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'appid' => $this->apiKey,
                    'units' => 'metric'
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $forecast = [];

                    foreach ($data['list'] as $item) {
                        $date = Carbon::parse($item['dt_txt'])->format('Y-m-d');
                        
                        if (!isset($forecast[$date])) {
                            $forecast[$date] = [
                                'date' => $date,
                                'temp_min' => $item['main']['temp_min'],
                                'temp_max' => $item['main']['temp_max'],
                                'humidity' => $item['main']['humidity'],
                                'rainfall' => $item['rain']['3h'] ?? 0,
                                'condition' => $item['weather'][0]['description'],
                                'icon' => $item['weather'][0]['icon']
                            ];
                        } else {
                            // Update min/max temperatures
                            $forecast[$date]['temp_min'] = min($forecast[$date]['temp_min'], $item['main']['temp_min']);
                            $forecast[$date]['temp_max'] = max($forecast[$date]['temp_max'], $item['main']['temp_max']);
                            $forecast[$date]['rainfall'] += $item['rain']['3h'] ?? 0;
                        }
                    }

                    return array_slice(array_values($forecast), 0, 5);
                } else {
                    Log::warning('Weather forecast API request failed', [
                        'status' => $response->status(),
                        'response' => $response->body()
                    ]);
                    return [];
                }
            } catch (\Exception $e) {
                Log::error('Weather forecast API error', [
                    'message' => $e->getMessage(),
                    'coordinates' => [$latitude, $longitude]
                ]);
                return [];
            }
        });
    }

    /**
     * Get farming-specific weather insights
     */
    public function getFarmingInsights($currentWeather, $forecast = [])
    {
        $insights = [
            'irrigation_recommendation' => $this->getIrrigationRecommendation($currentWeather, $forecast),
            'spraying_conditions' => $this->getSprayingConditions($currentWeather),
            'harvest_recommendation' => $this->getHarvestRecommendation($currentWeather, $forecast),
            'field_work_conditions' => $this->getFieldWorkConditions($currentWeather)
        ];

        return $insights;
    }

    /**
     * Get irrigation recommendations based on weather
     */
    private function getIrrigationRecommendation($weather, $forecast)
    {
        $rainfall24h = collect($forecast)->take(8)->sum('rainfall'); // Next 24 hours
        $humidity = $weather['humidity'];
        
        if ($rainfall24h > 5) {
            return [
                'status' => 'delay',
                'message' => 'High rainfall expected. Consider delaying irrigation.',
                'priority' => 'low'
            ];
        } elseif ($humidity < 40 && $weather['temperature'] > 30) {
            return [
                'status' => 'urgent',
                'message' => 'Low humidity and high temperature. Irrigation recommended.',
                'priority' => 'high'
            ];
        } else {
            return [
                'status' => 'normal',
                'message' => 'Normal irrigation schedule recommended.',
                'priority' => 'medium'
            ];
        }
    }

    /**
     * Get spraying conditions assessment
     */
    private function getSprayingConditions($weather)
    {
        $windSpeed = $weather['windSpeed'];
        $humidity = $weather['humidity'];
        $temperature = $weather['temperature'];

        if ($windSpeed > 15) {
            return [
                'status' => 'poor',
                'message' => 'High wind speed. Not suitable for spraying.',
                'priority' => 'high'
            ];
        } elseif ($humidity < 50 || $temperature > 35) {
            return [
                'status' => 'caution',
                'message' => 'Low humidity or high temperature. Exercise caution.',
                'priority' => 'medium'
            ];
        } else {
            return [
                'status' => 'good',
                'message' => 'Good conditions for spraying.',
                'priority' => 'low'
            ];
        }
    }

    /**
     * Get harvest recommendations
     */
    private function getHarvestRecommendation($weather, $forecast)
    {
        $rainfall24h = collect($forecast)->take(8)->sum('rainfall');
        
        if ($rainfall24h > 2) {
            return [
                'status' => 'delay',
                'message' => 'Rain expected. Consider shifting harvest schedule.',
                'priority' => 'high'
            ];
        } else {
            return [
                'status' => 'proceed',
                'message' => 'Weather conditions favorable for harvest.',
                'priority' => 'low'
            ];
        }
    }

    /**
     * Get field work conditions
     */
    private function getFieldWorkConditions($weather)
    {
        $temperature = $weather['temperature'];
        $humidity = $weather['humidity'];
        
        if ($temperature > 35 || $humidity > 90) {
            return [
                'status' => 'challenging',
                'message' => 'Extreme weather conditions. Plan work for early morning or evening.',
                'priority' => 'medium'
            ];
        } else {
            return [
                'status' => 'favorable',
                'message' => 'Good conditions for field work.',
                'priority' => 'low'
            ];
        }
    }

    /**
     * Get mock forecast data for development/testing
     */
    private function getMockForecastData($latitude, $longitude)
    {
        $forecast = [];
        $baseTemp = $latitude > 0 ? 20 : 28;
        
        for ($i = 1; $i <= 5; $i++) {
            $date = now()->addDays($i)->format('Y-m-d');
            $forecast[] = [
                'date' => $date,
                'temp_min' => $baseTemp + rand(-5, 0),
                'temp_max' => $baseTemp + rand(0, 10),
                'humidity' => rand(50, 85),
                'rainfall' => rand(0, 5),
                'condition' => ['Sunny', 'Partly cloudy', 'Cloudy', 'Light rain'][rand(0, 3)],
                'icon' => '02d'
            ];
        }
        
        return $forecast;
    }

    /**
     * Get mock weather data for development/testing
     */
    private function getMockWeatherData($latitude, $longitude)
    {
        // Generate realistic weather data based on location and time
        $hour = now()->hour;
        $baseTemp = $latitude > 0 ? 20 : 28; // Cooler in northern hemisphere
        $tempVariation = sin(($hour - 6) * pi() / 12) * 8; // Temperature variation throughout day
        
        return [
            'temperature' => round($baseTemp + $tempVariation),
            'humidity' => rand(50, 85),
            'windSpeed' => rand(5, 20),
            'rainfall' => rand(0, 2),
            'condition' => $hour > 6 && $hour < 18 ? 'Partly cloudy' : 'Clear sky',
            'icon' => $hour > 6 && $hour < 18 ? '02d' : '01n',
            'pressure' => rand(1008, 1020),
            'visibility' => rand(8, 15),
            'updated_at' => now()
        ];
    }

    /**
     * Get default weather data when API fails
     */
    private function getDefaultWeatherData()
    {
        return [
            'temperature' => 25,
            'humidity' => 65,
            'windSpeed' => 10,
            'rainfall' => 0,
            'condition' => 'Weather data unavailable',
            'icon' => '01d',
            'pressure' => 1013,
            'visibility' => 10,
            'updated_at' => now()
        ];
    }

    /**
     * Clear weather cache
     */
    public function clearCache($latitude = null, $longitude = null)
    {
        if ($latitude && $longitude) {
            Cache::forget($this->cachePrefix . "current_{$latitude}_{$longitude}");
            Cache::forget($this->cachePrefix . "forecast_{$latitude}_{$longitude}");
        } else {
            // Clear all weather cache
            Cache::flush(); // In production, use more specific cache clearing
        }
    }
}