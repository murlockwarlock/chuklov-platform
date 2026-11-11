<?php

namespace App\Support\Images;

final readonly class NormalizedUploadedImage
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public string $extension,
        public int $sizeBytes,
        public string $sourceMimeType,
        public int $width,
        public int $height,
    ) {}
}
