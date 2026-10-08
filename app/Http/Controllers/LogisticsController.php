<?php

namespace App\Http\Controllers;

use App\Models\SellOrder;
use App\Services\SikuMpyaLogisticsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class LogisticsController extends Controller
{
    protected $logisticsService;

    public function __construct(SikuMpyaLogisticsService $logisticsService)
    {
        $this->logisticsService = $logisticsService;
    }

    public function webhook(Request $request): JsonResponse
    {
        try {
            // Verify webhook signature if configured
            $webhookSecret = config('logistics.siku_mpya.webhook_secret');
            if ($webhookSecret) {
                $signature = $request->header('X-Webhook-Signature');
                $payload = $request->getContent();
                $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
                
                if (!hash_equals($expectedSignature, $signature)) {
                    Log::warning('Invalid webhook signature received', [
                        'signature' => $signature,
                        'expected' => $expectedSignature
                    ]);
                    
                    return response()->json(['error' => 'Invalid signature'], 401);
                }
            }

            // Process webhook
            $result = $this->logisticsService->processWebhook($request->all());
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Webhook processed successfully',
                    'data' => $result
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed',
                'error' => $result['error']
            ], 400);

        } catch (\Exception $e) {
            Log::error('Webhook processing exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Webhook processing failed',
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function getDeliveryStatus(Request $request, string $logisticsId): JsonResponse
    {
        try {
            $result = $this->logisticsService->getDeliveryStatus($logisticsId);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => $result
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to get delivery status',
                'error' => $result['error']
            ], 400);

        } catch (\Exception $e) {
            Log::error('Delivery status check failed', [
                'logistics_id' => $logisticsId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get delivery status',
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function estimateDelivery(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'pickup_latitude' => 'required|numeric|between:-90,90',
            'pickup_longitude' => 'required|numeric|between:-180,180',
            'delivery_latitude' => 'required|numeric|between:-90,90',
            'delivery_longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->logisticsService->estimateDeliveryTime(
                $request->pickup_latitude,
                $request->pickup_longitude,
                $request->delivery_latitude,
                $request->delivery_longitude
            );
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'distance_km' => $result['distance_km'],
                        'estimated_duration_minutes' => $result['estimated_duration_minutes'],
                        'estimated_cost' => $result['estimated_cost'],
                        'available_vehicles' => $result['available_vehicles']
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to get delivery estimate',
                'error' => $result['error']
            ], 400);

        } catch (\Exception $e) {
            Log::error('Delivery estimation failed', [
                'coordinates' => $request->only(['pickup_latitude', 'pickup_longitude', 'delivery_latitude', 'delivery_longitude']),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get delivery estimate',
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function getAvailableVehicles(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->logisticsService->getAvailableVehicles(
                $request->latitude,
                $request->longitude
            );
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'vehicles' => $result['vehicles'],
                        'count' => $result['count']
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to get available vehicles',
                'error' => $result['error']
            ], 400);

        } catch (\Exception $e) {
            Log::error('Available vehicles check failed', [
                'coordinates' => $request->only(['latitude', 'longitude']),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get available vehicles',
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function getServiceAreas(): JsonResponse
    {
        try {
            $serviceAreas = config('logistics.siku_mpya.service_areas', []);
            $vehicleTypes = config('logistics.siku_mpya.vehicle_types', []);
            $deliveryWindows = config('logistics.siku_mpya.delivery_windows', []);

            return response()->json([
                'success' => true,
                'data' => [
                    'service_areas' => $serviceAreas,
                    'vehicle_types' => $vehicleTypes,
                    'delivery_windows' => $deliveryWindows,
                    'max_delivery_radius_km' => config('logistics.settings.max_delivery_radius_km', 200)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Service areas retrieval failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get service areas',
                'error' => 'Internal server error'
            ], 500);
        }
    }

    public function cancelDelivery(Request $request, string $logisticsId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->logisticsService->cancelDelivery(
                $logisticsId,
                $request->reason ?? ''
            );
            
            if ($result['success']) {
                // Update sell order status
                $sellOrder = SellOrder::where('logistics_id', $logisticsId)->first();
                if ($sellOrder) {
                    $sellOrder->update([
                        'status' => 'cancelled',
                        'cancellation_reason' => $request->reason ?? 'Logistics cancelled'
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Delivery cancelled successfully',
                    'data' => [
                        'cancellation_fee' => $result['cancellation_fee'],
                        'refund_amount' => $result['refund_amount']
                    ]
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel delivery',
                'error' => $result['error']
            ], 400);

        } catch (\Exception $e) {
            Log::error('Delivery cancellation failed', [
                'logistics_id' => $logisticsId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel delivery',
                'error' => 'Internal server error'
            ], 500);
        }
    }
}