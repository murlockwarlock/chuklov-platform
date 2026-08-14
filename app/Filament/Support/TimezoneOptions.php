<?php

namespace App\Filament\Support;

final class TimezoneOptions
{
    /** @var array<string, string> */
    private const LABELS = [
        'UTC' => 'Всемирное время',
        'Asia/Almaty' => 'Алматы',
        'Asia/Aqtau' => 'Актау',
        'Asia/Atyrau' => 'Атырау',
        'Asia/Aqtobe' => 'Актобе',
        'Asia/Tashkent' => 'Ташкент',
        'Asia/Dubai' => 'Дубай',
        'Europe/Moscow' => 'Москва',
        'Europe/Berlin' => 'Берлин',
        'Europe/London' => 'Лондон',
        'Europe/Paris' => 'Париж',
        'America/New_York' => 'Нью-Йорк',
        'America/Los_Angeles' => 'Лос-Анджелес',
        'Asia/Tokyo' => 'Токио',
    ];

    /** @return array<string, string> */
    public static function options(?string $current = null, ?string $organization = null): array
    {
        $timezones = array_keys(self::LABELS);

        foreach ([$organization, $current] as $timezone) {
            if (is_string($timezone)
                && in_array($timezone, timezone_identifiers_list(), true)
                && ! in_array($timezone, $timezones, true)) {
                $timezones[] = $timezone;
            }
        }

        $options = [];

        foreach ($timezones as $timezone) {
            if (! in_array($timezone, timezone_identifiers_list(), true)) {
                continue;
            }

            $options[$timezone] = self::label($timezone);
        }

        return $options;
    }

    public static function label(?string $timezone): string
    {
        if ($timezone === null || $timezone === '') {
            return 'Не указан';
        }

        if (isset(self::LABELS[$timezone])) {
            return self::LABELS[$timezone];
        }

        $parts = explode('/', $timezone);
        $city = str_replace('_', ' ', (string) end($parts));

        return trim($city) === '' ? 'Часовой пояс' : $city;
    }
}
