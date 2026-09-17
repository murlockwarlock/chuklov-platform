<?php

return [
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),
    'fake_enabled' => (bool) env('FAKE_PAYMENT_GATEWAY_ENABLED', env('APP_ENV', 'production') !== 'production'),
    'lava' => [
        'base_url' => env('LAVA_API_BASE_URL', 'https://gate.lava.top'),
        'credential_name' => env('LAVA_CREDENTIAL_NAME', 'default'),
        'timeout_seconds' => (int) env('LAVA_API_TIMEOUT_SECONDS', 10),
    ],
];
