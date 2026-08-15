<?php

namespace App\Modules\Attachments\Domain\Contracts;

use App\Modules\Attachments\Domain\ValueObjects\ScanResult;
use Illuminate\Http\UploadedFile;

interface AttachmentScannerInterface
{
    public function scan(UploadedFile|string $file): ScanResult;
}
