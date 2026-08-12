<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;

final class TelegramMessagingChannel implements MessagingChannel
{
    public function name(): string
    {
        return 'telegram';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(
            supportsWebApp: true,
            supportsInlineButtons: true,
            supportsFileAttachments: true,
            supportsProactiveDelivery: true,
        );
    }
}
