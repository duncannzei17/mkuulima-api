<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class GeocodingService
{
    protected $apiKey;
    protected $cachePrefix = 'geocoding:';
    protected $cacheDuration = 1440; // 24 hours in minutes

    public function __construct()
    {
        $this->apiKey = config('services.openweather.api_key');
    }

    /**
     * Get coordinates from location string using OpenWeather Geocoding API
     */
    public function getCoordinatesFromLocation(string $location): ?array
    {
        if (empty($location)) {
            return null;
        }

        $cacheKey = $this->cachePrefix . md5($location);
        
        return Cache::remember($cacheKey, $this->cacheDuration * 60, function () use ($location) {
            try {
                if (!$this->apiKey) {
                    Log::warning('No OpenWeather API key configured for geocoding');
                    return null;
                }

                // Use OpenWeather Geocoding API
                $response = Http::get('http://api.openweathermap.org/geo/1.0/direct', [
                    'q' => $location,
                    'limit' => 1,
                    'appid' => $this->apiKey
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    if (empty($data)) {
                        Log::info("No geocoding results found for location: {$location}");
                        return null;
                    }

                    $result = $data[0];
                    
                    return [
                        'latitude' => $result['lat'],
                        'longitude' => $result['lon'],
                        'formatted_address' => $this->formatAddress($result),
                        'country' => $result['country'] ?? null,
                        'state' => $result['state'] ?? null,
                    ];
                }
                
                Log::error("Geocoding API request failed for location: {$location}", [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                
                return null;

            } catch (\Exception $e) {
                Log::error('Geocoding service error', [
                    'location' => $location,
                    'error' => $e->getMessage()
                ]);
                
                return null;
            }
        });
    }

    /**
     * Validate coordinates are within reasonable bounds
     */
    public function validateCoordinates(?float $latitude, ?float $longitude): bool
    {
        if ($latitude === null || $longitude === null) {
            return false;
        }

        return $latitude >= -90 && $latitude <= 90 && 
               $longitude >= -180 && $longitude <= 180;
    }

    /**
     * Get timezone from coordinates using OpenWeather API
     */
    public function getTimezoneFromCoordinates(float $latitude, float $longitude): ?string
    {
        $cacheKey = $this->cachePrefix . "tz_{$latitude}_{$longitude}";
        
        return Cache::remember($cacheKey, $this->cacheDuration * 60, function () use ($latitude, $longitude) {
            try {
                if (!$this->apiKey) {
                    return null;
                }

                // Get current weather which includes timezone
                $response = Http::get('http://api.openweathermap.org/data/2.5/weather', [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'appid' => $this->apiKey
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $timezoneOffset = $data['timezone'] ?? null;
                    
                    if ($timezoneOffset !== null) {
                        // Convert timezone offset to string format
                        $hours = intval($timezoneOffset / 3600);
                        $minutes = abs(($timezoneOffset % 3600) / 60);
                        $sign = $hours >= 0 ? '+' : '-';
                        
                        return sprintf('UTC%s%02d:%02d', $sign, abs($hours), $minutes);
                    }
                }
                
                return null;

            } catch (\Exception $e) {
                Log::error('Timezone lookup error', [
                    'coordinates' => [$latitude, $longitude],
                    'error' => $e->getMessage()
                ]);
                
                return null;
            }
        });
    }

    /**
     * Format address from geocoding result
     */
    protected function formatAddress(array $result): string
    {
        $parts = [];
        
        if (isset($result['name'])) {
            $parts[] = $result['name'];
        }
        
        if (isset($result['state']) && $result['state'] !== $result['name']) {
            $parts[] = $result['state'];
        }
        
        if (isset($result['country'])) {
            $parts[] = $result['country'];
        }
        
        return implode(', ', $parts);
    }

    /**
     * Parse coordinates from various input formats
     */
    public function parseCoordinatesInput($input): ?array
    {
        if (is_array($input)) {
            // Handle array input like ['lat' => -1.286, 'lng' => 36.817]
            $lat = $input['lat'] ?? $input['latitude'] ?? null;
            $lng = $input['lng'] ?? $input['longitude'] ?? null;
            
            if ($this->validateCoordinates($lat, $lng)) {
                return ['latitude' => (float)$lat, 'longitude' => (float)$lng];
            }
        } elseif (is_string($input)) {
            // Handle string input like "-1.286,36.817" or "-1.286, 36.817"
            $coords = explode(',', $input);
            if (count($coords) === 2) {
                $lat = trim($coords[0]);
                $lng = trim($coords[1]);
                
                if (is_numeric($lat) && is_numeric($lng)) {
                    $lat = (float)$lat;
                    $lng = (float)$lng;
                    
                    if ($this->validateCoordinates($lat, $lng)) {
                        return ['latitude' => $lat, 'longitude' => $lng];
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Get distance between two coordinate points in kilometers
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // Earth's radius in kilometers
        
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);
        
        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        
        return $earthRadius * $c;
    }
}