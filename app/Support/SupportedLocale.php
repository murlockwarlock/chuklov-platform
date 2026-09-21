<?php

namespace App\Support;

final class SupportedLocale
{
    public const AdminSessionKey = 'admin.locale';

    /** @return list<string> */
    public static function all(): array
    {
        return ['ru', 'en'];
    }

    public static function normalize(?string $value, string $fallback = 'ru'): string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized !== '') {
            $locale = substr($normalized, 0, 2);
            if (in_array($locale, self::all(), true)) {
                return $locale;
            }
        }

        $fallbackLocale = substr(strtolower(trim($fallback)), 0, 2);

        return in_array($fallbackLocale, self::all(), true) ? $fallbackLocale : 'ru';
    }
}
