<?php

namespace App\Modules\Channels\Domain\Enums;

enum TelegramMenuLaunchMode: string
{
    case MiniApp = 'mini_app';
    case ExternalUrl = 'external_url';
}
