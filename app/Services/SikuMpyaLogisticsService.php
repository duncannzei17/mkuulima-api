<?php

namespace App\Services;

use App\Models\SellOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SikuMpyaLogisticsService
{
    private $baseUrl;
    private $apiKey;
    private $timeout;
    
    public function __construct()
    {
        $this->baseUrl = config('logistics.siku_mpya.base_url', 'https://api.sikumpya.co.ke');
        $this->apiKey = config('logistics.siku_mpya.api_key');
        $this->timeout = config('logistics.siku_mpya.timeout', 30);
    }

    public function createDeliveryRequest(SellOrder $sellOrder): array
    {
        try {
            $payload = $this->buildDeliveryRequestPayload($sellOrder);
            
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])
                ->post($this->baseUrl . '/api/v1/deliveries', $payload);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('Siku Mpya delivery request created', [
                    'sell_order_id' => $sellOrder->id,
                    'logistics_id' => $data['delivery_id'] ?? null,
                    'response' => $data
                ]);

                return [
                    'success' => true,
                    'logistics_id' => $data['delivery_id'] ?? null,
                    'tracking_code' => $data['tracking_code'] ?? null,
                    'estimated_pickup_time' => $data['estimated_pickup_time'] ?? null,
                    'estimated_delivery_time' => $data['estimated_delivery_time'] ?? null,
                    'driver_info' => $data['driver'] ?? null,
                    'vehicle_info' => $data['vehicle'] ?? null,
                    'total_cost' => $data['total_cost'] ?? 0,
                    'raw_response' => $data
                ];
            }

            Log::error('Siku Mpya delivery request failed', [
                'sell_order_id' => $sellOrder->id,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return [
                'success' => false,
                'error' => 'Failed to create delivery request: ' . $response->body(),
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Siku Mpya service exception', [
                'sell_order_id' => $sellOrder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Service error: ' . $e->getMessage()
            ];
        }
    }

    public function getDeliveryStatus(string $logisticsId): array
    {
        try {
            $cacheKey = "siku_mpya_status_{$logisticsId}";
            
            // Cache status for 2 minutes to avoid excessive API calls
            return Cache::remember($cacheKey, 120, function () use ($logisticsId) {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Accept' => 'application/json'
                    ])
                    ->get($this->baseUrl . "/api/v1/deliveries/{$logisticsId}/status");

                if ($response->successful()) {
                    $data = $response->json();
                    
                    return [
                        'success' => true,
                        'status' => $data['status'] ?? 'unknown',
                        'location' => $data['current_location'] ?? null,
                        'estimated_arrival' => $data['estimated_arrival'] ?? null,
                        'driver_phone' => $data['driver']['phone'] ?? null,
                        'updates' => $data['status_updates'] ?? [],
                        'raw_response' => $data
                    ];
                }

                return [
                    'success' => false,
                    'error' => 'Failed to get delivery status',
                    'status_code' => $response->status()
                ];
            });

        } catch (\Exception $e) {
            Log::error('Siku Mpya status check failed', [
                'logistics_id' => $logisticsId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Status check failed: ' . $e->getMessage()
            ];
        }
    }

    public function estimateDeliveryTime(float $pickupLat, float $pickupLng, float $deliveryLat, float $deliveryLng): array
    {
        try {
            $cacheKey = "delivery_estimate_{$pickupLat}_{$pickupLng}_{$deliveryLat}_{$deliveryLng}";
            
            // Cache estimates for 1 hour
            return Cache::remember($cacheKey, 3600, function () use ($pickupLat, $pickupLng, $deliveryLat, $deliveryLng) {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Accept' => 'application/json'
                    ])
                    ->post($this->baseUrl . '/api/v1/estimate', [
                        'pickup' => [
                            'latitude' => $pickupLat,
                            'longitude' => $pickupLng
                        ],
                        'delivery' => [
                            'latitude' => $deliveryLat,
                            'longitude' => $deliveryLng
                        ]
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    return [
                        'success' => true,
                        'distance_km' => $data['distance_km'] ?? 0,
                        'estimated_duration_minutes' => $data['duration_minutes'] ?? 0,
                        'estimated_cost' => $data['estimated_cost'] ?? 0,
                        'available_vehicles' => $data['available_vehicles'] ?? [],
                        'raw_response' => $data
                    ];
                }

                return [
                    'success' => false,
                    'error' => 'Failed to get delivery estimate',
                    'status_code' => $response->status()
                ];
            });

        } catch (\Exception $e) {
            Log::error('Siku Mpya estimate failed', [
                'coordinates' => [$pickupLat, $pickupLng, $deliveryLat, $deliveryLng],
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Estimate failed: ' . $e->getMessage()
            ];
        }
    }

    public function cancelDelivery(string $logisticsId, string $reason = ''): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])
                ->post($this->baseUrl . "/api/v1/deliveries/{$logisticsId}/cancel", [
                    'reason' => $reason
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('Siku Mpya delivery cancelled', [
                    'logistics_id' => $logisticsId,
                    'reason' => $reason,
                    'response' => $data
                ]);

                // Clear cache
                Cache::forget("siku_mpya_status_{$logisticsId}");

                return [
                    'success' => true,
                    'cancellation_fee' => $data['cancellation_fee'] ?? 0,
                    'refund_amount' => $data['refund_amount'] ?? 0,
                    'raw_response' => $data
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to cancel delivery',
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('Siku Mpya cancellation failed', [
                'logistics_id' => $logisticsId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Cancellation failed: ' . $e->getMessage()
            ];
        }
    }

    public function getAvailableVehicles(float $pickupLat, float $pickupLng): array
    {
        try {
            $cacheKey = "available_vehicles_{$pickupLat}_{$pickupLng}";
            
            // Cache for 5 minutes
            return Cache::remember($cacheKey, 300, function () use ($pickupLat, $pickupLng) {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Accept' => 'application/json'
                    ])
                    ->get($this->baseUrl . '/api/v1/vehicles/available', [
                        'latitude' => $pickupLat,
                        'longitude' => $pickupLng,
                        'radius_km' => 50
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    return [
                        'success' => true,
                        'vehicles' => $data['vehicles'] ?? [],
                        'count' => count($data['vehicles'] ?? []),
                        'raw_response' => $data
                    ];
                }

                return [
                    'success' => false,
                    'error' => 'Failed to get available vehicles',
                    'status_code' => $response->status()
                ];
            });

        } catch (\Exception $e) {
            Log::error('Siku Mpya vehicle check failed', [
                'coordinates' => [$pickupLat, $pickupLng],
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Vehicle check failed: ' . $e->getMessage()
            ];
        }
    }

    public function processWebhook(array $webhookData): array
    {
        try {
            $logisticsId = $webhookData['delivery_id'] ?? null;
            $status = $webhookData['status'] ?? null;
            $eventType = $webhookData['event_type'] ?? null;

            if (!$logisticsId) {
                return [
                    'success' => false,
                    'error' => 'Missing delivery_id in webhook'
                ];
            }

            // Clear status cache
            Cache::forget("siku_mpya_status_{$logisticsId}");

            // Find the sell order
            $sellOrder = SellOrder::where('logistics_id', $logisticsId)->first();
            
            if (!$sellOrder) {
                Log::warning('Webhook received for unknown logistics ID', [
                    'logistics_id' => $logisticsId,
                    'webhook_data' => $webhookData
                ]);
                
                return [
                    'success' => false,
                    'error' => 'Unknown delivery ID'
                ];
            }

            // Update sell order status based on webhook
            $statusMapping = [
                'driver_assigned' => 'logistics_assigned',
                'picked_up' => 'picked_up',
                'in_transit' => 'in_transit',
                'delivered' => 'delivered',
                'cancelled' => 'cancelled'
            ];

            if (isset($statusMapping[$status])) {
                $sellOrder->update([
                    'status' => $statusMapping[$status],
                    'logistics_status' => $status,
                    'logistics_tracking_data' => array_merge(
                        $sellOrder->logistics_tracking_data ?? [],
                        [
                            'last_update' => now()->toISOString(),
                            'webhook_data' => $webhookData
                        ]
                    )
                ]);

                // Log status history
                $statusHistory = $sellOrder->status_history ?? [];
                $statusHistory[] = [
                    'status' => $statusMapping[$status],
                    'timestamp' => now()->toISOString(),
                    'source' => 'siku_mpya_webhook',
                    'data' => $webhookData
                ];
                
                $sellOrder->update(['status_history' => $statusHistory]);

                Log::info('Sell order status updated from webhook', [
                    'sell_order_id' => $sellOrder->id,
                    'old_status' => $sellOrder->getOriginal('status'),
                    'new_status' => $statusMapping[$status],
                    'webhook_status' => $status
                ]);
            }

            return [
                'success' => true,
                'sell_order_id' => $sellOrder->id,
                'status_updated' => isset($statusMapping[$status])
            ];

        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'webhook_data' => $webhookData,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Webhook processing failed: ' . $e->getMessage()
            ];
        }
    }

    private function buildDeliveryRequestPayload(SellOrder $sellOrder): array
    {
        return [
            'delivery_id' => $sellOrder->order_number,
            'pickup' => [
                'latitude' => $sellOrder->pickup_latitude,
                'longitude' => $sellOrder->pickup_longitude,
                'address' => $sellOrder->pickup_address,
                'contact_name' => $sellOrder->farmer_name,
                'contact_phone' => $sellOrder->farmer_phone,
                'instructions' => $sellOrder->pickup_instructions
            ],
            'delivery' => [
                'latitude' => $sellOrder->delivery_latitude,
                'longitude' => $sellOrder->delivery_longitude,
                'address' => $sellOrder->delivery_address,
                'contact_name' => $sellOrder->buyer_name,
                'contact_phone' => $sellOrder->buyer_phone,
                'instructions' => $sellOrder->delivery_instructions
            ],
            'cargo' => [
                'type' => 'agricultural_produce',
                'description' => $sellOrder->crop_name,
                'quantity' => $sellOrder->quantity,
                'unit' => $sellOrder->unit,
                'grade' => $sellOrder->grade,
                'estimated_weight_kg' => $this->estimateWeightInKg($sellOrder),
                'special_handling' => $sellOrder->special_handling_instructions,
                'temperature_sensitive' => in_array($sellOrder->crop_name, [
                    'tomatoes', 'lettuce', 'spinach', 'leafy_greens'
                ])
            ],
            'timing' => [
                'preferred_pickup_time' => $sellOrder->preferred_pickup_time,
                'latest_pickup_time' => $sellOrder->latest_pickup_time,
                'preferred_delivery_time' => $sellOrder->preferred_delivery_time
            ],
            'payment' => [
                'payment_method' => 'collect_on_delivery',
                'amount' => $sellOrder->agreed_total_price,
                'commission_percentage' => $sellOrder->platform_commission_percentage
            ],
            'metadata' => [
                'sell_order_id' => $sellOrder->id,
                'farm_id' => $sellOrder->farm_id,
                'buyer_id' => $sellOrder->buyer_id,
                'market_id' => $sellOrder->market_id
            ]
        ];
    }

    private function estimateWeightInKg(SellOrder $sellOrder): float
    {
        $quantity = $sellOrder->quantity;
        $unit = $sellOrder->unit;

        // Convert to kg based on unit
        switch ($unit) {
            case 'kg':
                return $quantity;
            case 'tons':
                return $quantity * 1000;
            case 'bags':
                return $quantity * 90; // Standard 90kg bag
            case 'crates':
                return $quantity * 20; // Estimated 20kg per crate
            case 'bundles':
                return $quantity * 5; // Estimated 5kg per bundle
            default:
                return $quantity; // Default to kg
        }
    }
}