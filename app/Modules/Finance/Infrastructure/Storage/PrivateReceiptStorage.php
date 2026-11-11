<?php

namespace App\Modules\Finance\Infrastructure\Storage;

use App\Modules\Finance\Domain\Contracts\ReceiptStorage;
use App\Modules\Finance\Domain\ValueObjects\StoredReceipt;
use App\Support\Images\ImageNormalizationException;
use App\Support\Images\NormalizedUploadedImage;
use App\Support\Images\NormalizeUploadedImage;
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
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly NormalizeUploadedImage $images) {}

    public function store(int $organizationId, UploadedFile $file): StoredReceipt
    {
        $realPath = $file->getRealPath();
        $size = $file->getSize();

        if (! $file->isValid() || $realPath === false || ! is_file($realPath) || $size === false || $size <= 0) {
            throw ValidationException::withMessages(['receipt' => 'Не удалось прочитать квитанцию.']);
        }

        if ($size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['receipt' => 'Квитанция должна быть размером до 10 МБ.']);
        }

        $mimeType = $this->sniffMimeType($realPath);
        $normalized = null;
        if ($this->isImageCandidate($file, $mimeType)) {
            try {
                $normalized = $this->images->normalize($file, self::MAX_BYTES);
            } catch (ImageNormalizationException $exception) {
                throw ValidationException::withMessages([
                    'receipt' => [$exception->reason === ImageNormalizationException::TOO_LARGE
                        ? 'Изображение должно быть размером до 10 МБ.'
                        : 'Не удалось прочитать изображение. Попробуйте другой файл.'],
                ]);
            }
        } elseif ($mimeType !== 'application/pdf') {
            throw ValidationException::withMessages(['receipt' => 'Квитанция должна быть PDF или изображением размером до 10 МБ.']);
        }

        $extension = $normalized?->extension ?? 'pdf';
        $path = 'finance/receipts/'.$organizationId.'/'.Str::uuid()->toString().'.'.$extension;
        $stored = $normalized instanceof NormalizedUploadedImage
            ? (Storage::disk('private')->put($path, $normalized->contents) ? $path : false)
            : Storage::disk('private')->putFileAs(dirname($path), $file, basename($path));

        if ($stored === false) {
            throw ValidationException::withMessages(['receipt' => 'Не удалось сохранить квитанцию.']);
        }

        return new StoredReceipt(
            disk: 'private',
            path: $stored,
            originalName: Str::limit(basename($file->getClientOriginalName()), 255, ''),
            mimeType: $normalized?->mimeType ?? $mimeType,
            sizeBytes: $normalized?->sizeBytes ?? $size,
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

    private function isImageCandidate(UploadedFile $file, string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/')
            || in_array(strtolower((string) $file->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'], true);
    }

    private function sniffMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mimeType = finfo_file($finfo, $path);
        unset($finfo);

        return is_string($mimeType) ? strtolower($mimeType) : 'application/octet-stream';
    }
}
