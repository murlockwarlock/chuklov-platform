<?php

return [
    'root_key' => env('MEDICAL_ENCRYPTION_KEY_V1'),
    'keys' => [
        1 => env('MEDICAL_ENCRYPTION_KEY_V1'),
    ],
    'current_version' => (int) env('MEDICAL_ENCRYPTION_KEY_VERSION', 1),
    'cipher' => 'AES-256-CBC',
    'attachment_max_bytes' => (int) env('MEDICAL_ATTACHMENT_MAX_BYTES', 20_971_520),
];
