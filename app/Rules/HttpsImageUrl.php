<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class HttpsImageUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return;
        }

        if (! is_string($value) || mb_strlen(trim($value)) > 2048
            || filter_var(trim($value), FILTER_VALIDATE_URL) === false
        ) {
            $fail('Укажите корректную HTTPS-ссылку на изображение.');

            return;
        }

        $parts = parse_url(trim($value));

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
        ) {
            $fail('Ссылка на изображение должна начинаться с HTTPS.');
        }
    }
}
