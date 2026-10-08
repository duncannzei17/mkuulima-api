<?php

return [

    'ussd' => [
        'callback_secret' => env('USSD_CALLBACK_SECRET'),
        'support_phone' => env('USSD_SUPPORT_PHONE', '0700000000'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Weather Services
    |--------------------------------------------------------------------------
    */

    'openweather' => [
        'api_key' => env('OPENWEATHER_API_KEY', null),
        'base_url' => 'https://api.openweathermap.org/data/2.5',
        'cache_duration' => env('WEATHER_CACHE_DURATION', 30), // minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | M-Pesa Integration
    |--------------------------------------------------------------------------
    */

    'mpesa' => [
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        'business_shortcode' => env('MPESA_BUSINESS_SHORTCODE'),
        'passkey' => env('MPESA_PASSKEY'),
        'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),
        'callback_url' => env('MPESA_CALLBACK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS Services
    |--------------------------------------------------------------------------
    */

    'sms' => [
        'provider' => env('SMS_PROVIDER', 'africastalking'),
        'africastalking' => [
            'username' => env('AFRICASTALKING_USERNAME'),
            'api_key' => env('AFRICASTALKING_API_KEY'),
            'sender_id' => env('SMS_SENDER_ID', 'FARMOS'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Notification Services
    |--------------------------------------------------------------------------
    */

    'pusher' => [
        'app_id' => env('PUSHER_APP_ID'),
        'key' => env('PUSHER_APP_KEY'),
        'secret' => env('PUSHER_APP_SECRET'),
        'cluster' => env('PUSHER_APP_CLUSTER'),
        'encrypted' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | External APIs
    |--------------------------------------------------------------------------
    */

    'logistics' => [
        'siku_mpya' => [
            'api_url' => env('SIKU_MPYA_API_URL'),
            'api_key' => env('SIKU_MPYA_API_KEY'),
        ]
    ],

];
