<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Channels\Domain\Contracts\NotificationChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;
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
        return new ChannelCapabilities(
            supportsWebApp: true,
            supportsInlineButtons: true,
            supportsProactiveDelivery: true,
        );
    }

    public function send(NotificationMessage $message): NotificationDeliveryResult
    {
        if (trim((string) config('nutgram.token')) === '') {
            return NotificationDeliveryResult::unavailable('provider_not_configured');
        }

        try {
            if ($message->webAppUrl !== null && ! $this->validWebAppUrl($message->webAppUrl)) {
                return NotificationDeliveryResult::unavailable('invalid_web_app_url');
            }

            $keyboard = $this->keyboard($message);
            if ($message->actionButton !== null && $keyboard === null) {
                return NotificationDeliveryResult::unavailable('invalid_notification_button');
            }

            $sent = $this->bot->sendMessage(
                $message->body,
                $message->recipientExternalId,
                reply_markup: $keyboard,
            );

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

    private function keyboard(NotificationMessage $message): ?InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();

        if ($message->actionButton !== null) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: $message->actionButton->text,
                url: $message->actionButton->url,
            ));
        }

        if ($message->webAppUrl !== null) {
            $url = $message->webAppUrl;
            if (! $this->validWebAppUrl($url)) {
                return null;
            }

            $keyboard->addRow(InlineKeyboardButton::make(
                text: $message->locale === 'ru' ? 'Оценить визит' : 'Rate your visit',
                web_app: WebAppInfo::make($url),
            ));
        }

        return ($message->actionButton !== null || $message->webAppUrl !== null) ? $keyboard : null;
    }

    private function validWebAppUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && is_string($parts['host'] ?? null)
            && trim($parts['host']) !== ''
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts);
    }
}
