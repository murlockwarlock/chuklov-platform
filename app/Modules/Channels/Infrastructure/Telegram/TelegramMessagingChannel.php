<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMedia;
use App\Support\RichText\RichTextDocument;
use Illuminate\Support\Facades\Log;
use JsonException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatAction;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

final class TelegramMessagingChannel implements MessagingChannel
{
    public function __construct(
        private readonly ?Nutgram $bot = null,
        private readonly ?TelegramCompanionFormatter $formatter = null,
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

    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        if ($this->bot === null || trim((string) config('nutgram.token')) === '') {
            return NotificationDeliveryResult::unavailable('provider_not_configured');
        }

        $formatter = $this->formatter ?? new TelegramCompanionFormatter;
        $recipientExternalId = $this->ensureUtf8($chunk->recipientExternalId, 'recipient_external_id');
        $semanticText = $this->ensureUtf8($chunk->semanticText, 'semantic_text');
        $chunks = RichTextDocument::isHtml($semanticText)
            ? $formatter->richTextChunks($semanticText)
            : $formatter->chunks($semanticText);
        $html = $chunks[$chunk->chunkIndex] ?? null;
        if ($html === null || count($chunks) !== $chunk->chunkCount) {
            return NotificationDeliveryResult::permanentFailure('formatting_contract_mismatch');
        }
        $html = $this->ensureUtf8($html, 'formatted_text');

        try {
            $keyboard = $this->keyboard($chunk);
            if ($chunk->mediaItems !== []) {
                return $this->sendMediaChunk($chunk, $html, $keyboard);
            }
            $sent = $this->bot->sendMessage($html, $recipientExternalId, parse_mode: ParseMode::HTML, reply_markup: $keyboard);

            return $this->resultForSentMessage($sent);
        } catch (Throwable $exception) {
            if ($this->isLocalPayloadEncodingFailure($exception)) {
                return $this->localPayloadFailure($exception);
            }
            if (! $this->isEntityParseFailure($exception)) {
                return $this->providerFailure($exception, 'telegram_api_error');
            }

            try {
                $fixed = $formatter->repairHtml($html);
                $fixed = $this->ensureUtf8($fixed, 'repaired_formatted_text');
                $sent = $this->bot->sendMessage($fixed, $recipientExternalId, parse_mode: ParseMode::HTML, reply_markup: $this->keyboard($chunk));

                return $this->resultForSentMessage($sent);
            } catch (Throwable $fixedException) {
                if ($this->isLocalPayloadEncodingFailure($fixedException)) {
                    return $this->localPayloadFailure($fixedException);
                }
                if (! $this->isEntityParseFailure($fixedException)) {
                    return $this->providerFailure($fixedException, 'telegram_repaired_html_error');
                }

                try {
                    $plainText = $this->ensureUtf8($formatter->plainText($html), 'plain_text');
                    $sent = $this->bot->sendMessage($plainText, $recipientExternalId, reply_markup: $this->keyboard($chunk));

                    return $this->resultForSentMessage($sent);
                } catch (Throwable $plainException) {
                    if ($this->isLocalPayloadEncodingFailure($plainException)) {
                        return $this->localPayloadFailure($plainException);
                    }
                    if (! $this->isEntityParseFailure($plainException)) {
                        return $this->providerFailure($plainException, 'telegram_plain_text_error');
                    }

                    return NotificationDeliveryResult::permanentFailure('telegram_formatting_rejected');
                }
            }
        }
    }

    private function sendMediaChunk(CompanionOutboundChunk $chunk, string $html, mixed $keyboard): NotificationDeliveryResult
    {
        if (count($chunk->mediaItems) !== 1) {
            return NotificationDeliveryResult::permanentFailure('companion_media_count_invalid');
        }

        $media = $chunk->mediaItems[0];
        if (! $media instanceof NotificationMedia || $media->stream === null || ! is_resource($media->stream)) {
            return NotificationDeliveryResult::permanentFailure('companion_media_unavailable');
        }

        if (mb_strlen($html) > 1024) {
            return NotificationDeliveryResult::permanentFailure('companion_media_caption_too_long');
        }

        try {
            $input = InputFile::make($media->stream, $media->fileName);
            $sent = match ($media->type) {
                'photo' => $this->bot->sendPhoto(
                    $input,
                    $chunk->recipientExternalId,
                    caption: $html === '' ? null : $html,
                    parse_mode: $html === '' ? null : ParseMode::HTML,
                    reply_markup: $keyboard,
                ),
                'document' => $this->bot->sendDocument(
                    $input,
                    $chunk->recipientExternalId,
                    caption: $html === '' ? null : $html,
                    parse_mode: $html === '' ? null : ParseMode::HTML,
                    reply_markup: $keyboard,
                ),
                default => throw new \InvalidArgumentException('Companion media type is unavailable.'),
            };

            $messageId = $sent?->message_id;

            return $messageId === null
                ? NotificationDeliveryResult::unknown('telegram_delivery_reference_missing')
                : NotificationDeliveryResult::delivered((string) $messageId);
        } catch (\InvalidArgumentException $exception) {
            return NotificationDeliveryResult::permanentFailure('companion_media_unavailable');
        } catch (Throwable $exception) {
            $code = (int) $exception->getCode();
            if ($code === 429 || $code >= 500) {
                return NotificationDeliveryResult::retryable('telegram_media_delivery_failed');
            }

            return NotificationDeliveryResult::unknown('telegram_media_delivery_unknown');
        } finally {
            fclose($media->stream);
        }
    }

    public function sendTyping(string $recipientExternalId): bool
    {
        if ($this->bot === null || trim((string) config('nutgram.token')) === '') {
            return false;
        }

        try {
            return $this->bot->sendChatAction(ChatAction::TYPING, $recipientExternalId) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function isEntityParseFailure(Throwable $exception): bool
    {
        return $exception->getCode() === 400
            && preg_match('/parse|entity|markup|tag/i', $exception->getMessage()) === 1;
    }

    private function providerFailure(Throwable $exception, string $fallbackCode): NotificationDeliveryResult
    {
        $code = (int) $exception->getCode();
        if ($code === 429) {
            $result = NotificationDeliveryResult::retryable('telegram_rate_limited');
            $this->logFailure($exception, $result);

            return $result;
        }
        if (in_array($code, [400, 401, 403, 404, 409], true)) {
            $result = NotificationDeliveryResult::permanentFailure('telegram_provider_rejected');
            $this->logFailure($exception, $result);

            return $result;
        }
        if ($code >= 500 && $code < 600) {
            $result = NotificationDeliveryResult::retryable('telegram_server_error');
            $this->logFailure($exception, $result);

            return $result;
        }

        $result = NotificationDeliveryResult::unknown($fallbackCode);
        $this->logFailure($exception, $result);

        return $result;
    }

    private function logFailure(Throwable $exception, NotificationDeliveryResult $result): void
    {
        Log::warning('companion_telegram_delivery_result', [
            'outcome' => $result->outcome->value,
            'error_code' => $result->errorCode,
            'provider_status' => (int) $exception->getCode(),
            'exception' => $exception::class,
            'exception_message' => mb_substr(trim($exception->getMessage()), 0, 240),
        ]);
    }

    private function resultForSentMessage(mixed $sent): NotificationDeliveryResult
    {
        $messageId = $sent?->message_id;
        if ($messageId === null) {
            $result = NotificationDeliveryResult::unknown('telegram_delivery_reference_missing');
            Log::warning('companion_telegram_delivery_result', [
                'outcome' => $result->outcome->value,
                'error_code' => $result->errorCode,
                'provider_status' => 200,
                'exception' => null,
                'exception_message' => 'Telegram returned no message identifier.',
            ]);

            return $result;
        }

        return NotificationDeliveryResult::delivered((string) $messageId);
    }

    private function keyboard(CompanionOutboundChunk $chunk): ?InlineKeyboardMarkup
    {
        if ($chunk->buttons === []) {
            return null;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($chunk->buttons as $index => $button) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: $this->ensureUtf8($button->text, "button.{$index}.text"),
                url: $button->url === null ? null : $this->ensureUtf8($button->url, "button.{$index}.url"),
                callback_data: $button->callbackData === null ? null : $this->ensureUtf8($button->callbackData, "button.{$index}.callback_data"),
            ));
        }

        return $keyboard;
    }

    private function ensureUtf8(string $value, string $field): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $sanitized = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        Log::warning('telegram_payload_utf8_repaired', [
            'field' => $field,
            'valid_utf8' => false,
            'byte_length' => strlen($value),
            'digest' => hash('sha256', $value),
            'sanitized_byte_length' => strlen($sanitized),
        ]);

        return $sanitized;
    }

    private function isLocalPayloadEncodingFailure(Throwable $exception): bool
    {
        return $exception instanceof JsonException && $exception->getCode() === JSON_ERROR_UTF8;
    }

    private function localPayloadFailure(JsonException $exception): NotificationDeliveryResult
    {
        $result = NotificationDeliveryResult::permanentFailure('telegram_payload_invalid_utf8');
        Log::warning('companion_telegram_delivery_result', [
            'outcome' => $result->outcome->value,
            'error_code' => $result->errorCode,
            'provider_status' => null,
            'failure_phase' => 'local_payload_serialization',
            'exception' => $exception::class,
            'exception_code' => $exception->getCode(),
            'exception_message' => mb_substr(trim($exception->getMessage()), 0, 240),
        ]);

        return $result;
    }
}
