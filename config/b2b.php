<?php

return [
    'sales_call' => [
        'duration_minutes' => max(1, (int) env('B2B_SALES_CALL_DURATION_MINUTES', 60)),
    ],
    'queue' => env('B2B_QUEUE', 'integrations'),
    'provider_lock_seconds' => max(30, (int) env('B2B_PROVIDER_LOCK_SECONDS', 60)),
    'credential_name' => env('B2B_ZOOM_CREDENTIAL_NAME', 'b2b_zoom'),
    'zoom' => [
        'api_base_url' => env('ZOOM_API_BASE_URL', 'https://api.zoom.us/v2'),
        'oauth_url' => env('ZOOM_OAUTH_URL', 'https://zoom.us/oauth/token'),
        'topic' => env('B2B_ZOOM_TOPIC', 'Chuklov B2B sales call'),
        'timeout_seconds' => max(1, (int) env('ZOOM_TIMEOUT_SECONDS', 15)),
    ],
    'events' => [
        'batch_size' => 100,
        'max_attempts' => 5,
        'retry_after_seconds' => 60,
        'stale_after_seconds' => 300,
    ],
];
