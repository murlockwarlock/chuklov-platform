<?php

return [
    'disk' => env('CONTENT_MEDIA_DISK', 'public'),
    'max_bytes' => (int) env('CONTENT_MEDIA_MAX_BYTES', 5_242_880),
];
