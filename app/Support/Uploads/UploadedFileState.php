<?php

namespace App\Support\Uploads;

use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class UploadedFileState
{
    public static function first(mixed $value): ?UploadedFile
    {
        if ($value instanceof UploadedFile) {
            return $value;
        }

        if (is_string($value) && str_starts_with($value, 'livewire-file:')) {
            $file = TemporaryUploadedFile::unserializeFromLivewireRequest($value);

            return $file instanceof UploadedFile ? $file : null;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach ($value as $item) {
            $file = self::first($item);

            if ($file instanceof UploadedFile) {
                return $file;
            }
        }

        return null;
    }
}
