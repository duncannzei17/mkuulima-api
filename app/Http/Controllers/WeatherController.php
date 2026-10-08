<?php

namespace App\Http\Controllers;

use App\Services\WeatherService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class WeatherController extends Controller
{
    protected $weatherService;

    public function __construct(WeatherService $weatherService)
    {
        $this->weatherService = $weatherService;
    }

    /**
     * Get current weather data
     */
    public function getCurrentWeather(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid coordinates provided',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $weather = $this->weatherService->getCurrentWeather(
                $request->latitude,
                $request->longitude
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'current' => $weather,
                    'location' => [
                        'latitude' => $request->latitude,
                        'longitude' => $request->longitude
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch weather data',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get weather forecast
     */
    public function getForecast(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid coordinates provided',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $forecast = $this->weatherService->getForecast(
                $request->latitude,
                $request->longitude
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'forecast' => $forecast,
                    'location' => [
                        'latitude' => $request->latitude,
                        'longitude' => $request->longitude
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch weather forecast',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get complete weather data with insights
     */
    public function getWeatherData(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid coordinates provided',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Get current weather and forecast
            $currentWeather = $this->weatherService->getCurrentWeather(
                $request->latitude,
                $request->longitude
            );

            $forecast = $this->weatherService->getForecast(
                $request->latitude,
                $request->longitude
            );

            // Get farming insights
            $insights = $this->weatherService->getFarmingInsights($currentWeather, $forecast);

            return response()->json([
                'success' => true,
                'data' => [
                    'current' => $currentWeather,
                    'forecast' => $forecast,
                    'insights' => $insights,
                    'location' => [
                        'latitude' => $request->latitude,
                        'longitude' => $request->longitude
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch complete weather data',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get farming insights based on current weather
     */
    public function getFarmingInsights(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid coordinates provided',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $currentWeather = $this->weatherService->getCurrentWeather(
                $request->latitude,
                $request->longitude
            );

            $forecast = $this->weatherService->getForecast(
                $request->latitude,
                $request->longitude
            );

            $insights = $this->weatherService->getFarmingInsights($currentWeather, $forecast);

            return response()->json([
                'success' => true,
                'data' => $insights
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate farming insights',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Clear weather cache
     */
    public function clearCache(Request $request): JsonResponse
    {
        try {
            $this->weatherService->clearCache(
                $request->latitude,
                $request->longitude
            );

            return response()->json([
                'success' => true,
                'message' => 'Weather cache cleared successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear weather cache',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}