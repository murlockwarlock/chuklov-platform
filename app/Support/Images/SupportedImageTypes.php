<?php

namespace App\Support\Images;

final class SupportedImageTypes
{
    public static function acceptedMimeTypes(): array
    {
        return [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/heic',
            'image/heif',
            'image/x-heic',
            'image/x-heif',
            'application/octet-stream',
        ];
    }

    public static function browserAccept(): string
    {
        return 'image/jpeg,image/png,image/webp,.heic,.heif';
    }
}
