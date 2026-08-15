<?php

namespace App\Modules\Attachments\Domain\ValueObjects;

final readonly class StoredAttachment
{
    public function __construct(
        public string $disk,
        public string $storagePath,
        public string $originalFilename,
        public string $mimeType,
        public int $sizeBytes,
        public string $sha256Checksum,
    ) {}
}
