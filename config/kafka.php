<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kafka Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Apache Kafka integration for event streaming
    | and ecosystem integration.
    |
    */

    'enabled' => env('KAFKA_ENABLED', false),

    'brokers' => env('KAFKA_BROKERS', 'localhost:9092'),

    'default_topic' => env('KAFKA_DEFAULT_TOPIC', 'farmOS-events'),

    'retry_attempts' => env('KAFKA_RETRY_ATTEMPTS', 3),

    'timeout_ms' => env('KAFKA_TIMEOUT_MS', 10000),

    /*
    |--------------------------------------------------------------------------
    | Topic Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for different event topics
    |
    */

    'topics' => [
        'market_ecosystem' => env('KAFKA_TOPIC_MARKET_ECOSYSTEM', 'market-ecosystem'),
        'sell_order_ecosystem' => env('KAFKA_TOPIC_SELL_ORDER_ECOSYSTEM', 'sell-order-ecosystem'),
        'sell_order_status' => env('KAFKA_TOPIC_SELL_ORDER_STATUS', 'sell-order-status'),
        'logistics_dispatch' => env('KAFKA_TOPIC_LOGISTICS_DISPATCH', 'logistics-dispatch-requests'),
        'logistics_ecosystem' => env('KAFKA_TOPIC_LOGISTICS_ECOSYSTEM', 'logistics-ecosystem'),
        'market_price_intelligence' => env('KAFKA_TOPIC_PRICE_INTELLIGENCE', 'market-price-intelligence'),
        'buyer_offers' => env('KAFKA_TOPIC_BUYER_OFFERS', 'buyer-offers'),
        'demand_forecasting' => env('KAFKA_TOPIC_DEMAND_FORECASTING', 'demand-forecasting'),
        'market_insights' => env('KAFKA_TOPIC_MARKET_INSIGHTS', 'market-insights'),
        'farmer_notifications' => env('KAFKA_TOPIC_FARMER_NOTIFICATIONS', 'farmer-notifications'),
        'buyer_notifications' => env('KAFKA_TOPIC_BUYER_NOTIFICATIONS', 'buyer-notifications'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Producer Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Kafka producer
    |
    */

    'producer' => [
        'client_id' => env('KAFKA_CLIENT_ID', 'farmOS-producer'),
        'acks' => env('KAFKA_ACKS', 'all'),
        'retries' => env('KAFKA_RETRIES', 3),
        'batch_size' => env('KAFKA_BATCH_SIZE', 16384),
        'linger_ms' => env('KAFKA_LINGER_MS', 5),
        'buffer_memory' => env('KAFKA_BUFFER_MEMORY', 33554432),
        'compression_type' => env('KAFKA_COMPRESSION_TYPE', 'gzip'),
        'max_request_size' => env('KAFKA_MAX_REQUEST_SIZE', 1048576),
    ],

    /*
    |--------------------------------------------------------------------------
    | Consumer Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Kafka consumer (for future use)
    |
    */

    'consumer' => [
        'group_id' => env('KAFKA_CONSUMER_GROUP_ID', 'farmOS-consumers'),
        'client_id' => env('KAFKA_CONSUMER_CLIENT_ID', 'farmOS-consumer'),
        'auto_offset_reset' => env('KAFKA_AUTO_OFFSET_RESET', 'earliest'),
        'enable_auto_commit' => env('KAFKA_ENABLE_AUTO_COMMIT', true),
        'auto_commit_interval_ms' => env('KAFKA_AUTO_COMMIT_INTERVAL_MS', 5000),
        'session_timeout_ms' => env('KAFKA_SESSION_TIMEOUT_MS', 30000),
        'heartbeat_interval_ms' => env('KAFKA_HEARTBEAT_INTERVAL_MS', 3000),
        'max_poll_records' => env('KAFKA_MAX_POLL_RECORDS', 500),
        'fetch_min_bytes' => env('KAFKA_FETCH_MIN_BYTES', 1),
        'fetch_max_wait_ms' => env('KAFKA_FETCH_MAX_WAIT_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Security settings for Kafka connection
    |
    */

    'security' => [
        'protocol' => env('KAFKA_SECURITY_PROTOCOL', 'PLAINTEXT'),
        'ssl' => [
            'ca_location' => env('KAFKA_SSL_CA_LOCATION'),
            'certificate_location' => env('KAFKA_SSL_CERTIFICATE_LOCATION'),
            'key_location' => env('KAFKA_SSL_KEY_LOCATION'),
            'key_password' => env('KAFKA_SSL_KEY_PASSWORD'),
            'verify_peer' => env('KAFKA_SSL_VERIFY_PEER', true),
        ],
        'sasl' => [
            'mechanism' => env('KAFKA_SASL_MECHANISM', 'PLAIN'),
            'username' => env('KAFKA_SASL_USERNAME'),
            'password' => env('KAFKA_SASL_PASSWORD'),
        ]
    ]

];