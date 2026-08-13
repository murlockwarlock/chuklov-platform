<?php

namespace App\Modules\Channels\Domain\Contracts;

use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;

interface NotificationChannel
{
    public function name(): string;

    public function capabilities(): ChannelCapabilities;

    public function send(NotificationMessage $message): NotificationDeliveryResult;
}
