<?php

return [
    
    /*
    |--------------------------------------------------------------------------
    | Default Logistics Provider
    |--------------------------------------------------------------------------
    |
    | This option defines the default logistics provider to use for deliveries.
    | Currently supported: "siku_mpya"
    |
    */

    'default' => env('LOGISTICS_PROVIDER', 'siku_mpya'),

    /*
    |--------------------------------------------------------------------------
    | Siku Mpya Logistics Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Siku Mpya logistics integration
    |
    */

    'siku_mpya' => [
        'base_url' => env('SIKU_MPYA_BASE_URL', 'https://api.sikumpya.co.ke'),
        'api_key' => env('SIKU_MPYA_API_KEY'),
        'webhook_secret' => env('SIKU_MPYA_WEBHOOK_SECRET'),
        'timeout' => env('SIKU_MPYA_TIMEOUT', 30),
        'environment' => env('SIKU_MPYA_ENVIRONMENT', 'sandbox'), // sandbox or production
        'default_commission_percentage' => env('SIKU_MPYA_COMMISSION_PERCENTAGE', 5.0),
        
        // Service areas (counties where service is available)
        'service_areas' => [
            'Nairobi', 'Kiambu', 'Nakuru', 'Meru', 'Nyeri', 'Murang\'a',
            'Kajiado', 'Machakos', 'Makueni', 'Embu', 'Kirinyaga',
            'Nyandarua', 'Laikipia', 'Uasin Gishu', 'Trans Nzoia'
        ],
        
        // Delivery time windows
        'delivery_windows' => [
            'express' => '2-4 hours',
            'same_day' => '6-8 hours', 
            'next_day' => '24 hours',
            'standard' => '48 hours'
        ],
        
        // Vehicle types and capacities
        'vehicle_types' => [
            'motorcycle' => [
                'max_weight_kg' => 50,
                'max_volume_m3' => 0.2,
                'cost_per_km' => 20
            ],
            'pickup' => [
                'max_weight_kg' => 500,
                'max_volume_m3' => 2.0,
                'cost_per_km' => 50
            ],
            'van' => [
                'max_weight_kg' => 1000,
                'max_volume_m3' => 5.0,
                'cost_per_km' => 80
            ],
            'truck' => [
                'max_weight_kg' => 5000,
                'max_volume_m3' => 15.0,
                'cost_per_km' => 120
            ]
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | General Logistics Settings
    |--------------------------------------------------------------------------
    |
    | General settings for logistics operations
    |
    */

    'settings' => [
        'max_delivery_radius_km' => 200,
        'default_pickup_buffer_minutes' => 30,
        'default_delivery_buffer_minutes' => 60,
        'auto_assign_logistics' => env('AUTO_ASSIGN_LOGISTICS', true),
        'enable_real_time_tracking' => env('ENABLE_REAL_TIME_TRACKING', true),
        'webhook_retry_attempts' => 3,
        'status_cache_duration_seconds' => 120
    ]

];