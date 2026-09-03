<?php

namespace App\Modules\Broadcasts\Domain\Contracts;

use Illuminate\Http\UploadedFile;

interface BroadcastMediaStorageInterface
{
    public function store(int $organizationId, UploadedFile $file): string;

    public function isManagedPath(int $organizationId, ?string $path): bool;

    public function readStream(int $organizationId, string $path): mixed;

    public function deleteManaged(int $organizationId, string $path): void;
}
