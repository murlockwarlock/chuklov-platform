<?php

return [
    'queue' => env('REFERRALS_QUEUE', 'referrals'),
    'events' => [
        'batch_size' => 100,
        'max_attempts' => 5,
        'retry_after_seconds' => 60,
        'stale_after_seconds' => 300,
    ],
];
