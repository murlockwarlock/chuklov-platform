<?php

namespace App\Modules\Referrals\Domain\Enums;

enum ReferralCampaignChannel: string
{
    case Telegram = 'telegram';
    case Instagram = 'instagram';
    case YouTube = 'youtube';
    case WhatsApp = 'whatsapp';
    case Website = 'website';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Telegram => 'Telegram',
            self::Instagram => 'Instagram',
            self::YouTube => 'YouTube',
            self::WhatsApp => 'WhatsApp',
            self::Website => 'Сайт',
            self::Other => 'Другое',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            static fn (array $options, self $channel): array => $options + [$channel->value => $channel->label()],
            [],
        );
    }
}
