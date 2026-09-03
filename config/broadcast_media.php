<?php

return [
    'disk' => env('BROADCAST_MEDIA_DISK', 'private'),
    'max_bytes' => (int) env('BROADCAST_MEDIA_MAX_BYTES', 52_428_800),
    'photo_max_bytes' => (int) env('BROADCAST_PHOTO_MAX_BYTES', 10_485_760),
    'max_items' => 10,
];
