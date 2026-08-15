<?php

namespace App\Modules\Attachments\Infrastructure\Scanning;

use App\Modules\Attachments\Domain\Contracts\AttachmentScannerInterface;
use App\Modules\Attachments\Domain\ValueObjects\ScanResult;
use Illuminate\Http\UploadedFile;

final class LocalDeterministicAttachmentScanner implements AttachmentScannerInterface
{
    public function scan(UploadedFile|string $file): ScanResult
    {
        $filename = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);
        $realPath = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        $contentSample = '';

        if ($realPath !== false && file_exists($realPath) && is_readable($realPath)) {
            $handle = fopen($realPath, 'rb');

            if ($handle !== false) {
                $contentSample = (string) fread($handle, 4096);
                fclose($handle);
            }
        }

        if (str_contains($filename, 'test-trigger-quarantine') || str_contains($contentSample, 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*')) {
            return ScanResult::quarantined(
                reason: 'Обнаружена тестовая вредоносная сигнатура.',
                scannerName: 'deterministic_foundation',
                metadata: ['matched_rule' => 'eicar_test_trigger'],
            );
        }

        if (str_contains($filename, 'test-trigger-reject') || str_contains($contentSample, 'TEST_MALWARE_REJECT_TRIGGER')) {
            return ScanResult::rejected(
                reason: 'Файл отклонён политикой безопасности.',
                scannerName: 'deterministic_foundation',
                metadata: ['matched_rule' => 'policy_reject_trigger'],
            );
        }

        return ScanResult::cleared(
            scannerName: 'deterministic_foundation',
            metadata: ['scanned_at' => now()->toISOString()],
        );
    }
}
