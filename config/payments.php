<?php

return [
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),
    'fake_enabled' => (bool) env('FAKE_PAYMENT_GATEWAY_ENABLED', env('APP_ENV', 'production') !== 'production'),
];
