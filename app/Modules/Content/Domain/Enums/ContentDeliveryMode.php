<?php

namespace App\Modules\Content\Domain\Enums;

enum ContentDeliveryMode: string
{
    case Telegram = 'telegram';
    case MiniApp = 'mini_app';
    case Both = 'both';

    public function supportsTelegram(): bool
    {
        return in_array($this, [self::Telegram, self::Both], true);
    }

    public function supportsMiniApp(): bool
    {
        return in_array($this, [self::MiniApp, self::Both], true);
    }
}
