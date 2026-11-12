<?php

return [
    'disk' => env('SPECIALIST_MEDIA_DISK', 'public'),
    'max_bytes' => (int) env('SPECIALIST_MEDIA_MAX_BYTES', 15_728_640),
];
