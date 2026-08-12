<?php

namespace Tests\Unit;

use App\Modules\Channels\Infrastructure\Telegram\TelegramMessagingChannel;
use Tests\TestCase;

class MilestoneTwoChannelBoundaryTest extends TestCase
{
    public function test_telegram_exposes_a_channel_neutral_capability_snapshot(): void
    {
        $channel = new TelegramMessagingChannel;

        self::assertSame('telegram', $channel->name());
        self::assertSame([
            'web_app' => true,
            'inline_buttons' => true,
            'file_attachments' => true,
            'proactive_delivery' => true,
            'threads' => false,
        ], $channel->capabilities()->toArray());
    }
}
