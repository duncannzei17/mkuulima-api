<?php

return [
    // Set explicit ingress proxy IPs when traffic is forwarded to the app.
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1'),
];
