<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

final class TelegramDeliveryErrorClassifier
{
    public function classify(TelegramException $exception, NotificationMessage $message): string
    {
        $description = mb_strtolower($exception->getMessage());

        return match (true) {
            $this->containsAny($description, ['bot was blocked', 'blocked by the user']) => 'telegram_bot_blocked',
            $this->containsAny($description, ['chat not found']) => 'telegram_chat_not_found',
            $this->containsAny($description, ['user is deactivated', 'account is deactivated']) => 'telegram_user_deactivated',
            $this->containsAny($description, ["can't parse entities", 'cannot parse entities', 'cant parse entities']) => 'telegram_formatting_rejected',
            $this->containsAny($description, ['message is too long', 'caption is too long', 'text is too long']) => 'telegram_message_too_long',
            $message->mode->includesImage() && $this->containsAny($description, [
                'failed to get http url content',
                'wrong type of the web page content',
                'wrong file identifier',
                'photo_invalid',
                'image_process_failed',
                'photo is too big',
                'file is too big',
            ]) => 'telegram_media_unavailable',
            default => 'telegram_provider_rejected',
        };
    }

    /** @param array<string> $needles */
    private function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }
}
