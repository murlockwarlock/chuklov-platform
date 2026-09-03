<?php

namespace App\Modules\Content\Domain\Contracts;

use Illuminate\Http\UploadedFile;

interface ContentMediaStorageInterface
{
    public function store(int $organizationId, UploadedFile $file): string;

    public function isManagedPath(int $organizationId, ?string $path): bool;

    public function readStream(int $organizationId, string $path): mixed;

    public function url(string $path): string;

    public function deleteManaged(int $organizationId, string $path): void;
}
