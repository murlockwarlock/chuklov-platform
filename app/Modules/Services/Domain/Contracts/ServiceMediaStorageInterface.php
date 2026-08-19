<?php

namespace App\Modules\Services\Domain\Contracts;

use Illuminate\Http\UploadedFile;

interface ServiceMediaStorageInterface
{
    public function store(int $organizationId, UploadedFile $file): string;

    public function isManagedPath(int $organizationId, ?string $path): bool;

    public function url(string $path): string;

    public function deleteManaged(int $organizationId, string $path): void;
}
