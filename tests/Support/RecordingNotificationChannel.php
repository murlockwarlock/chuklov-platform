<?php

namespace Tests\Support;

use App\Modules\Channels\Domain\Contracts\NotificationChannel;
use App\Modules\Channels\Domain\Exceptions\NotificationDeliveryException;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use Closure;

final class RecordingNotificationChannel implements NotificationChannel
{
    /** @var list<NotificationMessage> */
    public array $messages = [];

    public bool $throwBeforeSend = false;

    public bool $throwAfterSend = false;

    public ?Closure $onSend = null;

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
        return new ChannelCapabilities(
            supportsInlineButtons: true,
            supportsProactiveDelivery: true,
        );
    }

    public function send(NotificationMessage $message): NotificationDeliveryResult
    {
        if ($this->throwBeforeSend) {
            throw new NotificationDeliveryException(false, 'simulated pre-send failure');
        }

        $this->messages[] = $message;
        $this->onSend?->__invoke($message);

        if ($this->throwAfterSend) {
            throw new NotificationDeliveryException(true, 'simulated acknowledgement loss');
        }

        return $this->configuredResult ?? NotificationDeliveryResult::delivered('fake-'.$message->idempotencyKey);
    }
}
