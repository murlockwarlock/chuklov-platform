<?php

namespace App\Modules\Finance\Domain\Contracts;

use App\Modules\Finance\Domain\ValueObjects\StoredReceipt;
use Illuminate\Http\UploadedFile;

interface ReceiptStorage
{
    public function store(int $organizationId, UploadedFile $file): StoredReceipt;

    public function delete(string $path): void;

    /** @return resource */
    public function readStream(string $path);
}
