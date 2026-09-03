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
        $timezones = timezone_identifiers_list();
        $preferred = [];

        foreach ([$organization, $current] as $timezone) {
            if (is_string($timezone) && in_array($timezone, $timezones, true)) {
                $preferred[] = $timezone;
            }
        }

        $timezones = array_values(array_unique([...$preferred, ...$timezones]));

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

        $label = self::LABELS[$timezone] ?? self::cityLabel($timezone);

        return $label === 'Часовой пояс' ? $timezone : $label.' ('.$timezone.')';
    }

    private static function cityLabel(string $timezone): string
    {
        $parts = explode('/', $timezone);
        $city = str_replace('_', ' ', (string) end($parts));

        return trim($city) === '' ? 'Часовой пояс' : $city;
    }
}
