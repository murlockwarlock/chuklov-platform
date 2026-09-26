<?php

return [
    'queue' => env('SCENARIO_QUEUE', 'scenarios'),
    'scheduler' => [
        'batch_size' => 100,
        'stale_after_seconds' => 300,
    ],
    'events' => [
        'max_attempts' => 5,
        'retry_after_seconds' => 60,
    ],
    'retention_default_delay_days' => (int) env('SCENARIO_RETENTION_DEFAULT_DELAY_DAYS', 30),
    'deliveries' => [
        'max_attempts' => 3,
        'retry_after_seconds' => 300,
    ],
];
