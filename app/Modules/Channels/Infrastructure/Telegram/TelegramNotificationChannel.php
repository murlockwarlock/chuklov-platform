<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Channels\Domain\Contracts\NotificationChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use Throwable;

final class TelegramNotificationChannel implements NotificationChannel
{
    public function __construct(private readonly Nutgram $bot) {}

    public function name(): string
    {
        return 'telegram';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(supportsProactiveDelivery: true);
    }

    public function send(NotificationMessage $message): NotificationDeliveryResult
    {
        if (trim((string) config('nutgram.token')) === '') {
            return NotificationDeliveryResult::unavailable('provider_not_configured');
        }

        try {
            $sent = $this->bot->sendMessage($message->body, $message->recipientExternalId);

            return NotificationDeliveryResult::delivered(
                $sent?->message_id === null ? null : (string) $sent->message_id,
            );
        } catch (TelegramException $exception) {
            $code = $exception->getCode();
            if ($code === 429) {
                return $message->requireKnownExternalOutcome
                    ? NotificationDeliveryResult::retryable('telegram_rate_limited')
                    : NotificationDeliveryResult::retryable('telegram_api_error');
            }
            if ($code >= 400 && $code < 500) {
                return $message->requireKnownExternalOutcome
                    ? NotificationDeliveryResult::permanentFailure('telegram_provider_rejected')
                    : NotificationDeliveryResult::retryable('telegram_api_error');
            }

            return $message->requireKnownExternalOutcome
                ? NotificationDeliveryResult::unknown('delivery_outcome_unknown')
                : NotificationDeliveryResult::retryable('telegram_api_error');
        } catch (Throwable) {
            return $message->requireKnownExternalOutcome
                ? NotificationDeliveryResult::unknown('delivery_outcome_unknown')
                : NotificationDeliveryResult::retryable('channel_error');
        }
    }
}
