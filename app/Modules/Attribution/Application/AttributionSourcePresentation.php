<?php

namespace App\Modules\Attribution\Application;

final class AttributionSourcePresentation
{
    private const array SourceLabels = [
        'friend' => 'По рекомендации знакомых',
        'social' => 'Социальные сети',
        'search' => 'Поиск в интернете',
        'partner' => 'Партнёр',
        'other' => 'Другое',
    ];

    private const array TechnicalLabels = [
        'telegram' => 'Telegram',
        'email_auth' => 'Email',
        'portal' => 'Портал',
        'site' => 'Сайт',
        'web' => 'Сайт',
        'website' => 'Сайт',
        'instagram' => 'Instagram',
        'facebook' => 'Социальные сети',
        'vk' => 'Социальные сети',
        'yandex' => 'Yandex',
        'google' => 'Google',
        'source' => 'Не указан',
        'manual' => 'Не указан',
        'legacy' => 'Не указан',
    ];

    public static function label(?string $source, ?string $sourceType = null): string
    {
        $value = trim((string) $source);

        if ($value !== '') {
            $key = mb_strtolower($value);

            return self::SourceLabels[$key] ?? self::TechnicalLabels[$key] ?? $value;
        }

        return match (mb_strtolower(trim((string) $sourceType))) {
            'referral' => 'Реферальный переход',
            'utm' => 'UTM-метки',
            default => 'Не указан',
        };
    }

    /** @return array<string, string> */
    public static function knownLabels(): array
    {
        return self::SourceLabels;
    }

    /** @return array<string, string> */
    public static function directSourceLabels(): array
    {
        return [
            ...self::SourceLabels,
            ...array_diff_key(self::TechnicalLabels, array_flip(['source', 'manual', 'legacy'])),
        ];
    }
}
