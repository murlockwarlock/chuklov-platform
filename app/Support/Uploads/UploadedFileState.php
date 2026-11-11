<?php

namespace App\Support\Uploads;

use Illuminate\Http\UploadedFile;

final class UploadedFileState
{
    public static function first(mixed $value): ?UploadedFile
    {
        if ($value instanceof UploadedFile) {
            return $value;
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
