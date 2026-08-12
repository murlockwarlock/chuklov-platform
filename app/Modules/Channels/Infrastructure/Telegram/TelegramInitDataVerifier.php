<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Identity\Application\VerifiedChannelIdentity;
use Illuminate\Contracts\Cache\Repository;
use SergiX44\Nutgram\Nutgram;
use Throwable;

class TelegramInitDataVerifier
{
    public function __construct(
        private readonly Nutgram $bot,
        private readonly Repository $cache,
    ) {}

    public function handle(string $initData): VerifiedChannelIdentity
    {
        if (trim($initData) === '') {
            throw new InvalidTelegramInitData('Telegram initData is missing.');
        }

        try {
            $data = $this->bot->validateWebAppData($initData);
        } catch (Throwable) {
            throw new InvalidTelegramInitData('Telegram initData is invalid.');
        }

        if ($data->user === null || $data->user->is_bot) {
            throw new InvalidTelegramInitData('Telegram user data is missing.');
        }

        $now = now()->getTimestamp();
        $authDate = $data->auth_date->getTimestamp();
        $ttl = max(1, (int) config('nutgram.mini_app.auth_ttl', 900));
        $clockSkew = max(0, (int) config('nutgram.mini_app.clock_skew', 30));
        $age = $now - $authDate;

        if ($age > $ttl || $age < -$clockSkew) {
            throw new InvalidTelegramInitData('Telegram initData is stale.');
        }

        $fingerprint = hash('sha256', $initData);

        if (! $this->cache->add('telegram.mini_app.init_data.'.$fingerprint, true, $ttl)) {
            throw new InvalidTelegramInitData('Telegram initData was already used.');
        }

        $displayName = trim(implode(' ', array_filter([
            $data->user->first_name,
            $data->user->last_name,
        ])));

        if ($displayName === '') {
            throw new InvalidTelegramInitData('Telegram display name is missing.');
        }

        $language = strtolower((string) ($data->user->language_code ?? 'en'));
        $language = preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $language) === 1 ? $language : 'en';

        return new VerifiedChannelIdentity(
            channel: 'telegram',
            externalId: (string) $data->user->id,
            displayName: mb_substr($displayName, 0, 160),
            language: $language,
        );
    }
}
