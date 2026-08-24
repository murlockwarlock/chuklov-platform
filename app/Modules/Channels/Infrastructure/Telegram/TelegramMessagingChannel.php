<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatAction;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
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
        $chunks = $formatter->chunks($chunk->semanticText);
        $html = $chunks[$chunk->chunkIndex] ?? null;
        if ($html === null || count($chunks) !== $chunk->chunkCount) {
            return NotificationDeliveryResult::permanentFailure('formatting_contract_mismatch');
        }

        try {
            $keyboard = $this->keyboard($chunk);
            $sent = $this->bot->sendMessage($html, $chunk->recipientExternalId, parse_mode: ParseMode::HTML, reply_markup: $keyboard);

            return NotificationDeliveryResult::delivered($sent?->message_id === null ? null : (string) $sent->message_id);
        } catch (Throwable $exception) {
            if (! $this->isEntityParseFailure($exception)) {
                return $this->providerFailure($exception, 'telegram_api_error');
            }

            try {
                $fixed = $formatter->repairHtml($html);
                $sent = $this->bot->sendMessage($fixed, $chunk->recipientExternalId, parse_mode: ParseMode::HTML, reply_markup: $this->keyboard($chunk));

                return NotificationDeliveryResult::delivered($sent?->message_id === null ? null : (string) $sent->message_id);
            } catch (Throwable $fixedException) {
                if (! $this->isEntityParseFailure($fixedException)) {
                    return $this->providerFailure($fixedException, 'telegram_repaired_html_error');
                }

                try {
                    $sent = $this->bot->sendMessage($formatter->plainText($html), $chunk->recipientExternalId, reply_markup: $this->keyboard($chunk));

                    return NotificationDeliveryResult::delivered($sent?->message_id === null ? null : (string) $sent->message_id);
                } catch (Throwable $plainException) {
                    if (! $this->isEntityParseFailure($plainException)) {
                        return $this->providerFailure($plainException, 'telegram_plain_text_error');
                    }

                    return NotificationDeliveryResult::permanentFailure('telegram_formatting_rejected');
                }
            }
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
            return NotificationDeliveryResult::retryable('telegram_rate_limited');
        }
        if (in_array($code, [400, 401, 403, 404, 409], true)) {
            return NotificationDeliveryResult::permanentFailure('telegram_provider_rejected');
        }

        return NotificationDeliveryResult::unknown($fallbackCode);
    }

    private function keyboard(CompanionOutboundChunk $chunk): ?InlineKeyboardMarkup
    {
        if ($chunk->buttons === []) {
            return null;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($chunk->buttons as $button) {
            $keyboard->addRow(InlineKeyboardButton::make(
                text: $button->text,
                url: $button->url,
                callback_data: $button->callbackData,
            ));
        }

        return $keyboard;
    }
}
