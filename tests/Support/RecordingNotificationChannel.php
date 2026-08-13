<?php

namespace Tests\Support;

use App\Modules\Channels\Domain\Contracts\NotificationChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;

final class RecordingNotificationChannel implements NotificationChannel
{
    /** @var list<NotificationMessage> */
    public array $messages = [];

    public function __construct(
        private readonly string $channelName = 'telegram',
        private readonly ?NotificationDeliveryResult $configuredResult = null,
    ) {}

    public function name(): string
    {
        return $this->channelName;
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(supportsProactiveDelivery: true);
    }

    public function send(NotificationMessage $message): NotificationDeliveryResult
    {
        $this->messages[] = $message;

        return $this->configuredResult ?? NotificationDeliveryResult::delivered('fake-'.$message->idempotencyKey);
    }
}
