<?php

namespace App\Modules\Finance\Infrastructure\Storage;

use App\Modules\Finance\Domain\Contracts\ReceiptStorage;
use App\Modules\Finance\Domain\ValueObjects\StoredReceipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PrivateReceiptStorage implements ReceiptStorage
{
    private const MAX_BYTES = 10_485_760;

    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function store(int $organizationId, UploadedFile $file): StoredReceipt
    {
        $mimeType = (string) $file->getMimeType();
        $size = $file->getSize();

        if (! $file->isValid() || ! isset(self::MIME_EXTENSIONS[$mimeType]) || $size === false || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['receipt' => 'Квитанция должна быть PDF, JPG или PNG размером до 10 МБ.']);
        }

        $extension = self::MIME_EXTENSIONS[$mimeType];
        $path = 'finance/receipts/'.$organizationId.'/'.Str::uuid()->toString().'.'.$extension;
        $stored = Storage::disk('private')->putFileAs(dirname($path), $file, basename($path));

        if ($stored === false) {
            throw ValidationException::withMessages(['receipt' => 'Не удалось сохранить квитанцию.']);
        }

        return new StoredReceipt(
            disk: 'private',
            path: $stored,
            originalName: Str::limit(basename($file->getClientOriginalName()), 255, ''),
            mimeType: $mimeType,
            sizeBytes: $size,
        );
    }

    public function delete(string $path): void
    {
        Storage::disk('private')->delete($path);
    }

    public function readStream(string $path)
    {
        $stream = Storage::disk('private')->readStream($path);

        if (! is_resource($stream)) {
            throw new \RuntimeException('The receipt file is unavailable.');
        }

        return $stream;
    }
}
