<?php

return [
    'disk' => env('SERVICE_MEDIA_DISK', 'public'),
    'max_bytes' => (int) env('SERVICE_MEDIA_MAX_BYTES', 5_242_880),
];
