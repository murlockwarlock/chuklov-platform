<?php

namespace App\Modules\AI\Domain\ValueObjects;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AiTechnicalKey
{
    public static function normalize(mixed $value, string $label = 'key'): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("The {$label} is invalid.");
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 80 || preg_match('/^[a-z0-9_-]+$/', $value) !== 1) {
            throw new InvalidArgumentException("The {$label} is invalid.");
        }

        return $value;
    }

    public static function fromHumanName(string $name, string $fallback): string
    {
        $slug = Str::slug(trim($name), '-');
        $slug = mb_substr($slug, 0, 80);
        $slug = rtrim($slug, '-_');

        if ($slug === '') {
            $slug = Str::slug(trim($fallback), '-');
        }

        return self::normalize($slug !== '' ? $slug : 'item');
    }
}
