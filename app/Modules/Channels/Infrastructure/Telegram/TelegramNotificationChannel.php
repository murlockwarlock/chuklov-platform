<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Channels\Domain\Contracts\NotificationChannel;
use App\Modules\Channels\Domain\Enums\NotificationMessageMode;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMedia;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Support\RichText\RichTextDocument;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaDocument;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaPhoto;
use SergiX44\Nutgram\Telegram\Types\Input\InputMediaVideo;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;
use Throwable;

final class TelegramNotificationChannel implements NotificationChannel
{
    public function __construct(
        private readonly Nutgram $bot,
        private readonly TelegramDeliveryErrorClassifier $errorClassifier = new TelegramDeliveryErrorClassifier,
    ) {}

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
            $this->closeMedia($message);

            return NotificationDeliveryResult::unavailable('provider_not_configured');
        }

        try {
            if ($message->webAppUrl !== null && ! $this->validWebAppUrl($message->webAppUrl)) {
                return NotificationDeliveryResult::unavailable('invalid_web_app_url');
            }

            $keyboard = $this->keyboard($message);
            if (($message->actionButton !== null || $message->actionButtons !== []) && $keyboard === null) {
                return NotificationDeliveryResult::unavailable('invalid_notification_button');
            }

            $telegramBody = RichTextDocument::telegramHtml($message->body);
            $this->validateLength($message, $telegramBody);
            $successfulSends = 0;
            $lastMessageId = null;

            if ($message->mode === NotificationMessageMode::ImageThenText) {
                $lastMessageId = $this->sendMedia($message, null, null);
                $successfulSends++;
                $lastMessageId = $this->sendText($message, $telegramBody, $keyboard);
                $successfulSends++;
            } elseif ($message->mode === NotificationMessageMode::TextThenImage) {
                $lastMessageId = $this->sendText($message, $telegramBody, null);
                $successfulSends++;
                $lastMessageId = $this->sendMedia($message, null, $keyboard);
                $successfulSends++;
            } elseif ($message->mode === NotificationMessageMode::Image || $message->mode === NotificationMessageMode::ImageWithCaption) {
                $lastMessageId = $this->sendMedia($message, $message->mode === NotificationMessageMode::ImageWithCaption ? $telegramBody : null, $keyboard);
                $successfulSends++;
            } else {
                $lastMessageId = $this->sendText($message, $telegramBody, $keyboard);
                $successfulSends++;
            }

            return NotificationDeliveryResult::delivered(
                $lastMessageId,
            );
        } catch (\InvalidArgumentException $exception) {
            $description = mb_strtolower($exception->getMessage());
            $errorCode = str_contains($description, 'media')
                ? 'media_unavailable'
                : (str_contains($description, 'too long') ? 'telegram_message_too_long' : 'telegram_message_invalid');

            return NotificationDeliveryResult::permanentFailure($errorCode);
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
                    ? NotificationDeliveryResult::permanentFailure($this->errorClassifier->classify($exception, $message))
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
        } finally {
            $this->closeMedia($message);
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
        try {
            $sent = $this->bot->sendPhoto(
                $this->photo($message),
                $message->recipientExternalId,
                caption: $caption === '' ? null : $caption,
                parse_mode: $caption === null || $caption === '' ? null : ParseMode::HTML,
                reply_markup: $keyboard,
                show_caption_above_media: $message->mode === NotificationMessageMode::ImageWithCaption
                    ? $message->showCaptionAboveMedia
                    : null,
            );

            return $sent?->message_id === null ? null : (string) $sent->message_id;
        } finally {
            $this->closeMedia($message);
        }
    }

    private function sendMedia(NotificationMessage $message, ?string $caption, ?InlineKeyboardMarkup $keyboard): ?string
    {
        if ($message->mediaItems === []) {
            return $this->sendPhoto($message, $caption, $keyboard);
        }

        try {
            if (count($message->mediaItems) > 1) {
                if ($keyboard !== null) {
                    throw new \InvalidArgumentException('Telegram media groups do not support inline buttons.');
                }

                return $this->sendMediaGroup($message, $caption);
            }

            $media = $message->mediaItems[0];

            return $this->sendSingleMedia($message, $media, $caption, $keyboard);
        } finally {
            $this->closeMediaItems($message->mediaItems);
        }
    }

    private function sendSingleMedia(
        NotificationMessage $message,
        NotificationMedia $media,
        ?string $caption,
        ?InlineKeyboardMarkup $keyboard,
    ): ?string {
        $uploadable = $this->uploadable($media);
        $caption = $caption === '' ? null : $caption;
        $parseMode = $caption === null ? null : ParseMode::HTML;
        $showCaptionAboveMedia = $message->mode === NotificationMessageMode::ImageWithCaption
            ? $message->showCaptionAboveMedia
            : null;

        return match ($media->type) {
            'photo' => $this->messageId($this->bot->sendPhoto(
                $uploadable,
                $message->recipientExternalId,
                caption: $caption,
                parse_mode: $parseMode,
                reply_markup: $keyboard,
                show_caption_above_media: $showCaptionAboveMedia,
            )),
            'video' => $this->messageId($this->bot->sendVideo(
                $uploadable,
                $message->recipientExternalId,
                caption: $caption,
                parse_mode: $parseMode,
                reply_markup: $keyboard,
                show_caption_above_media: $showCaptionAboveMedia,
            )),
            'document' => $this->messageId($this->bot->sendDocument(
                $uploadable,
                $message->recipientExternalId,
                caption: $caption,
                parse_mode: $parseMode,
                reply_markup: $keyboard,
            )),
            default => throw new \InvalidArgumentException('The Telegram media type is unavailable.'),
        };
    }

    private function sendMediaGroup(NotificationMessage $message, ?string $caption): ?string
    {
        if (count($message->mediaItems) < 2 || count($message->mediaItems) > 10) {
            throw new \InvalidArgumentException('The Telegram media group is unavailable.');
        }

        $types = array_map(static fn (NotificationMedia $item): string => $item->type, $message->mediaItems);
        if (in_array('', $types, true)
            || (in_array('document', $types, true) && count(array_unique($types)) > 1)) {
            throw new \InvalidArgumentException('The Telegram media group is unavailable.');
        }

        $media = [];
        foreach ($message->mediaItems as $index => $item) {
            $itemCaption = $index === 0 && $caption !== '' ? $caption : null;
            $parseMode = $itemCaption === null ? null : ParseMode::HTML;
            $showCaptionAboveMedia = null;
            if ($itemCaption !== null && $message->mode === NotificationMessageMode::ImageWithCaption) {
                $showCaptionAboveMedia = $message->showCaptionAboveMedia;
            }
            $uploadable = $this->uploadable($item, $index);

            $media[] = match ($item->type) {
                'photo' => InputMediaPhoto::make(
                    media: $uploadable,
                    caption: $itemCaption,
                    parse_mode: $parseMode,
                    show_caption_above_media: $showCaptionAboveMedia,
                ),
                'video' => InputMediaVideo::make(
                    media: $uploadable,
                    caption: $itemCaption,
                    parse_mode: $parseMode,
                    show_caption_above_media: $showCaptionAboveMedia,
                ),
                'document' => InputMediaDocument::make(
                    media: $uploadable,
                    caption: $itemCaption,
                    parse_mode: $parseMode,
                ),
                default => throw new \InvalidArgumentException('The Telegram media type is unavailable.'),
            };
        }

        $sent = $this->bot->sendMediaGroup($media, $message->recipientExternalId);
        $last = is_array($sent) ? end($sent) : null;

        return $this->messageId($last);
    }

    private function closeMedia(NotificationMessage $message): void
    {
        if (is_resource($message->mediaStream)) {
            fclose($message->mediaStream);
        }

        $this->closeMediaItems($message->mediaItems);
    }

    /** @param list<NotificationMedia> $mediaItems */
    private function closeMediaItems(array $mediaItems): void
    {
        foreach ($mediaItems as $media) {
            if (is_resource($media->stream)) {
                fclose($media->stream);
            }
        }
    }

    private function photo(NotificationMessage $message): InputFile|string
    {
        if ($message->mediaStream !== null) {
            if (! is_resource($message->mediaStream)) {
                throw new \InvalidArgumentException('The Telegram media is unavailable.');
            }

            try {
                return InputFile::make($message->mediaStream);
            } catch (\InvalidArgumentException $exception) {
                throw new \InvalidArgumentException('The Telegram media is unavailable.', previous: $exception);
            }
        }

        if ($message->mediaUrl === null || ! $this->validMediaUrl($message->mediaUrl)) {
            throw new \InvalidArgumentException('The Telegram media URL is invalid.');
        }

        return $message->mediaUrl;
    }

    private function uploadable(NotificationMedia $media, ?int $index = null): InputFile|string
    {
        if ($media->stream !== null) {
            if (! is_resource($media->stream)) {
                throw new \InvalidArgumentException('The Telegram media is unavailable.');
            }

            try {
                return InputFile::make($media->stream, $this->uploadFileName($media->fileName, $index));
            } catch (\InvalidArgumentException $exception) {
                throw new \InvalidArgumentException('The Telegram media is unavailable.', previous: $exception);
            }
        }

        if ($media->url === null || ! $this->validMediaUrl($media->url)) {
            throw new \InvalidArgumentException('The Telegram media URL is invalid.');
        }

        return $media->url;
    }

    private function uploadFileName(?string $fileName, ?int $index): ?string
    {
        if ($fileName === null || $index === null) {
            return $fileName;
        }

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $base = pathinfo($fileName, PATHINFO_FILENAME);
        $suffix = '-'.($index + 1);

        return $extension === '' ? $base.$suffix : $base.$suffix.'.'.$extension;
    }

    private function messageId(mixed $message): ?string
    {
        return $message instanceof Message ? (string) $message->message_id : null;
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

        foreach ($message->actionButtons as $actionButton) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: $actionButton->text,
                url: $actionButton->url,
                callback_data: $actionButton->callbackData,
            ));
        }

        if ($message->actionButton !== null) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: $message->actionButton->text,
                url: $message->actionButton->url,
                callback_data: $message->actionButton->callbackData,
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

        return ($message->actionButton !== null || $message->actionButtons !== [] || $message->webAppUrl !== null) ? $keyboard : null;
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
