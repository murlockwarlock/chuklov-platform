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
}
