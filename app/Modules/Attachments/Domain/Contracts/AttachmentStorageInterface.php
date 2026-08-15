<?php

namespace App\Modules\Attachments\Domain\Contracts;

use App\Modules\Attachments\Domain\ValueObjects\StoredAttachment;
use Illuminate\Http\UploadedFile;

interface AttachmentStorageInterface
{
    public function store(int $organizationId, UploadedFile $file, string $uuid): StoredAttachment;

    /** @return resource */
    public function readStream(string $storagePath);

    public function delete(string $storagePath): void;
}
