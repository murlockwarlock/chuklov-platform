<?php

namespace App\Modules\Finance\Domain\ValueObjects;

final readonly class StoredReceipt
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes,
    ) {}
}
