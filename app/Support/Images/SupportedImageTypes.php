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

    /** @return array<string, string> */
    public static function mimeTypeMap(): array
    {
        return [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
        ];
    }
}
