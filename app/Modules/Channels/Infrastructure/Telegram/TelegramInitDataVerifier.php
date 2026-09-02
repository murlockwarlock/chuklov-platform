<?php

namespace App\Modules\Channels\Infrastructure\Telegram;

use App\Modules\Identity\Application\VerifiedChannelIdentity;
use Illuminate\Contracts\Cache\Repository;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Web\WebAppData;
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

        $fingerprint = $this->fingerprint($data);

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
            startParameter: is_string($data->start_param) ? mb_substr(trim($data->start_param), 0, 128) : null,
            username: $data->user->username,
        );
    }

    private function fingerprint(WebAppData $data): string
    {
        $canonicalData = $data->toArray();
        unset($canonicalData['hash']);
        $canonicalData['auth_date'] = $data->auth_date->getTimestamp();

        return hash('sha256', json_encode(
            $this->canonicalize($canonicalData),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
