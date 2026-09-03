<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Channels\Domain\Contracts\NotificationChannel;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Support\RichText\RichTextDocument;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
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
            supportsFileAttachments: true,
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

            $telegramBody = RichTextDocument::telegramHtml($message->body);
            $this->validateLength($message, $telegramBody);
            $successfulSends = 0;
            $lastMessageId = null;

            if ($message->mode === NotificationMessageMode::ImageThenText) {
                $lastMessageId = $this->sendPhoto($message, null, null);
                $successfulSends++;
                $lastMessageId = $this->sendText($message, $telegramBody, $keyboard);
                $successfulSends++;
            } elseif ($message->mode === NotificationMessageMode::TextThenImage) {
                $lastMessageId = $this->sendText($message, $telegramBody, null);
                $successfulSends++;
                $lastMessageId = $this->sendPhoto($message, null, $keyboard);
                $successfulSends++;
            } elseif ($message->mode === NotificationMessageMode::Image || $message->mode === NotificationMessageMode::ImageWithCaption) {
                $lastMessageId = $this->sendPhoto($message, $message->mode === NotificationMessageMode::ImageWithCaption ? $telegramBody : null, $keyboard);
                $successfulSends++;
            } else {
                $lastMessageId = $this->sendText($message, $telegramBody, $keyboard);
                $successfulSends++;
            }

            return NotificationDeliveryResult::delivered(
                $lastMessageId,
            );
        } catch (\InvalidArgumentException $exception) {
            return NotificationDeliveryResult::permanentFailure(
                str_contains(strtolower($exception->getMessage()), 'too long')
                    ? 'telegram_message_too_long'
                    : 'telegram_message_invalid',
            );
        } catch (TelegramException $exception) {
            $code = (int) $exception->getCode();
            $successfulSends = $successfulSends ?? 0;
            if ($successfulSends > 0) {
                return NotificationDeliveryResult::unknown('delivery_outcome_unknown');
            }
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
            if (($successfulSends ?? 0) > 0) {
                return NotificationDeliveryResult::unknown('delivery_outcome_unknown');
            }

            return $message->requireKnownExternalOutcome
                ? NotificationDeliveryResult::unknown('delivery_outcome_unknown')
                : NotificationDeliveryResult::retryable('channel_error');
        }
    }

    private function sendText(NotificationMessage $message, string $body, ?InlineKeyboardMarkup $keyboard): ?string
    {
        if ($body === '') {
            return null;
        }

        $sent = $this->bot->sendMessage(
            $body,
            $message->recipientExternalId,
            parse_mode: ParseMode::HTML,
            reply_markup: $keyboard,
        );

        return $sent?->message_id === null ? null : (string) $sent->message_id;
    }

    private function sendPhoto(NotificationMessage $message, ?string $caption, ?InlineKeyboardMarkup $keyboard): ?string
    {
        if ($message->mediaUrl === null || ! $this->validMediaUrl($message->mediaUrl)) {
            throw new \InvalidArgumentException('The Telegram media URL is invalid.');
        }

        $sent = $this->bot->sendPhoto(
            $message->mediaUrl,
            $message->recipientExternalId,
            caption: $caption === '' ? null : $caption,
            parse_mode: $caption === null || $caption === '' ? null : ParseMode::HTML,
            reply_markup: $keyboard,
            show_caption_above_media: $message->mode === NotificationMessageMode::ImageWithCaption
                ? $message->showCaptionAboveMedia
                : null,
        );

        return $sent?->message_id === null ? null : (string) $sent->message_id;
    }

    private function validateLength(NotificationMessage $message, string $body): void
    {
        $limit = $message->mode->usesCaption()
            ? RichTextDocument::TELEGRAM_CAPTION_LIMIT
            : RichTextDocument::TELEGRAM_TEXT_LIMIT;

        if ($message->mode->includesText() && RichTextDocument::renderedTelegramLength($body) > $limit) {
            throw new \InvalidArgumentException('The Telegram message is too long.');
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

    private function validMediaUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && trim($parts['host']) !== ''
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts);
    }
}
