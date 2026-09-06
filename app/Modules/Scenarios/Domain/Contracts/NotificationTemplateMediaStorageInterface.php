<?php

namespace App\Modules\Scenarios\Domain\Contracts;

use Illuminate\Http\UploadedFile;

interface NotificationTemplateMediaStorageInterface
{
    public function store(int $organizationId, UploadedFile $file): string;

    public function isManagedPath(int $organizationId, ?string $path): bool;

    public function readStream(int $organizationId, string $path): mixed;

    public function delete(int $organizationId, string $path): void;
}
