<?php

namespace App\Modules\Attachments\Application\DTOs;

final readonly class AttachmentDownloadResult
{
    /**
     * @param  resource  $stream
     */
    public function __construct(
        public mixed $stream,
        public string $filename,
        public string $mimeType,
        public int $sizeBytes,
    ) {}
}
