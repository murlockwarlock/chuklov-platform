<?php

namespace App\Modules\AI\Domain\ValueObjects;

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
}
