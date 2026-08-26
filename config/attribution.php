<?php

return [
    'pre_auth_ttl_seconds' => (int) env('ATTRIBUTION_PRE_AUTH_TTL', 1800),
    'max_source_length' => 120,
    'max_utm_length' => 160,
    'max_referral_code_length' => 128,
    'manual_sources' => [
        'friend',
        'social',
        'search',
        'partner',
        'other',
    ],
];
