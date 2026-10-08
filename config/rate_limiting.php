<?php

return [
    'api' => [
        'authenticated_per_minute' => (int) env('API_RATE_LIMIT_PER_MINUTE', 300),
        'guest_per_minute' => (int) env('API_GUEST_RATE_LIMIT_PER_MINUTE', 120),
    ],

    'auth_per_minute' => (int) env('AUTH_RATE_LIMIT_PER_MINUTE', 10),
    'token_refresh_per_minute' => (int) env('TOKEN_REFRESH_RATE_LIMIT_PER_MINUTE', 30),
];
