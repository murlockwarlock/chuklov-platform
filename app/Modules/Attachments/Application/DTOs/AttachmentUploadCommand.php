<?php

namespace App\Modules\Attachments\Application\DTOs;

use App\Modules\Attachments\Domain\Enums\AttachmentType;
use Illuminate\Http\UploadedFile;

final readonly class AttachmentUploadCommand
{
    public function __construct(
        public UploadedFile $file,
        public int $clientId,
        public AttachmentType $attachmentType = AttachmentType::MedicalReport,
    ) {}
}
