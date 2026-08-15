<?php

namespace App\Modules\Attachments\Infrastructure\Scanning;

use App\Modules\Attachments\Domain\Contracts\AttachmentScannerInterface;
use App\Modules\Attachments\Domain\ValueObjects\ScanResult;
use Illuminate\Http\UploadedFile;

final class FailClosedAttachmentScanner implements AttachmentScannerInterface
{
    public function scan(UploadedFile|string $file): ScanResult
    {
        return ScanResult::quarantined(
            reason: 'Антивирусный сканер не настроен на сервере. Файл помещён на карантин.',
            scannerName: 'runtime_fail_closed',
            metadata: [
                'scanned_at' => now()->toISOString(),
                'reason' => 'scanner_unavailable',
            ],
        );
    }
}
