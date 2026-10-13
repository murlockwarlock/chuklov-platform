<?php

return [
    'disk' => env('CONTENT_MEDIA_DISK', 'public'),
    'max_bytes' => (int) env('CONTENT_MEDIA_MAX_BYTES', 5_242_880),
    'max_video_bytes' => (int) env('CONTENT_MEDIA_MAX_VIDEO_BYTES', 10_485_760),
];
