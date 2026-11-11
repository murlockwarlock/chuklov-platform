<?php

namespace Tests\Unit\Support;

use App\Support\Images\SupportedImageTypes;
use PHPUnit\Framework\TestCase;

final class SupportedImageTypesTest extends TestCase
{
    public function test_browser_file_type_map_covers_supported_phone_image_extensions(): void
    {
        self::assertSame([
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
            'heif' => 'image/heif',
        ], SupportedImageTypes::mimeTypeMap());
    }

    public function test_browser_accept_uses_mime_types_for_heif_family(): void
    {
        self::assertSame(
            'image/jpeg,image/png,image/webp,image/heic,image/heif',
            SupportedImageTypes::browserAccept(),
        );
    }
}
