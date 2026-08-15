<?php

namespace App\Modules\Attachments\Domain\ValueObjects;

use App\Modules\Attachments\Domain\Enums\AttachmentScanStatus;

final class ScanResult
{
    /** @var array<string, mixed> */
    public readonly array $metadata;

    /** @param  array<string, mixed>  $metadata */
    public function __construct(
        public readonly AttachmentScanStatus $status,
        public readonly string $scannerName,
        array $metadata = [],
    ) {
        $merged = array_merge(['scanner_name' => $scannerName], $metadata);
        $this->metadata = self::sanitizeMetadata($merged);
    }

    /** @param  array<string, mixed>  $metadata */
    public static function cleared(string $scannerName = 'deterministic_foundation', array $metadata = []): self
    {
        return new self(
            status: AttachmentScanStatus::Cleared,
            scannerName: $scannerName,
            metadata: $metadata,
        );
    }

    /** @param  array<string, mixed>  $metadata */
    public static function quarantined(string $reason, string $scannerName = 'deterministic_foundation', array $metadata = []): self
    {
        return new self(
            status: AttachmentScanStatus::Quarantined,
            scannerName: $scannerName,
            metadata: array_merge($metadata, ['reason' => $reason]),
        );
    }

    /** @param  array<string, mixed>  $metadata */
    public static function rejected(string $reason, string $scannerName = 'deterministic_foundation', array $metadata = []): self
    {
        return new self(
            status: AttachmentScanStatus::Rejected,
            scannerName: $scannerName,
            metadata: array_merge($metadata, ['reason' => $reason]),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function sanitizeMetadata(array $metadata): array
    {
        $allowed = ['scanner_name', 'scanned_at', 'matched_rule', 'reason'];

        return array_filter(
            $metadata,
            fn (string $key): bool => in_array($key, $allowed, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
