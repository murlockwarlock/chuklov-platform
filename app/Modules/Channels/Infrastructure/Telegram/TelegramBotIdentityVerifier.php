<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Identity\Application\VerifiedChannelIdentity;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class TelegramBotIdentityVerifier
{
    public function handle(Nutgram $bot): VerifiedChannelIdentity
    {
        $user = $bot->user();

        if ($user === null || $user->is_bot || (int) $user->id <= 0) {
            throw new UnauthorizedHttpException('Telegram', 'The Telegram user evidence is invalid.');
        }

        $displayName = trim(implode(' ', array_filter([
            $user->first_name,
            $user->last_name,
        ])));

        if ($displayName === '') {
            throw new UnauthorizedHttpException('Telegram', 'The Telegram user evidence is incomplete.');
        }

        $language = strtolower((string) ($user->language_code ?? 'en'));
        $language = preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $language) === 1 ? $language : 'en';

        return new VerifiedChannelIdentity(
            channel: 'telegram',
            externalId: (string) $user->id,
            displayName: mb_substr($displayName, 0, 160),
            language: $language,
        );
    }
}
