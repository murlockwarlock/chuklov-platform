<?php

return [
    'queue' => env('B2B_QUEUE', 'integrations'),
    'credential_name' => env('B2B_ZOOM_CREDENTIAL_NAME', 'b2b_zoom'),
    'provider' => [
        'operation_deadline_seconds' => max(15, (int) env('B2B_PROVIDER_OPERATION_DEADLINE_SECONDS', 90)),
        'lease_margin_seconds' => max(5, (int) env('B2B_PROVIDER_LEASE_MARGIN_SECONDS', 15)),
        'request_safety_seconds' => max(1, (int) env('B2B_PROVIDER_REQUEST_SAFETY_SECONDS', 2)),
        'list_page_size' => min(300, max(1, (int) env('B2B_PROVIDER_LIST_PAGE_SIZE', 100))),
        'list_max_pages' => min(20, max(1, (int) env('B2B_PROVIDER_LIST_MAX_PAGES', 5))),
    ],
    'availability' => [
        'max_specialists' => 20,
        'max_slots' => 200,
    ],
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
