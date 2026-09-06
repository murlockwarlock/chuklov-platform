<?php

namespace App\Modules\Referrals\Application;

use LogicException;

final class BuildReferralTelegramUrl
{
    public function handle(string $token): string
    {
        $botUsername = trim((string) config('portal.telegram.bot_username'));

        if ($botUsername === '' || preg_match('/^[A-Za-z0-9_]{5,32}$/', $botUsername) !== 1) {
            throw new LogicException('Telegram referral links are not configured.');
        }

        $token = trim($token);

        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $token) !== 1) {
            throw new LogicException('The referral token is invalid.');
        }

        $payload = 'ref_'.$token;

        if (strlen($payload) > 64) {
            throw new LogicException('The referral token does not fit Telegram deep-link limits.');
        }

        return 'https://t.me/'.$botUsername.'?'.http_build_query(
            ['start' => $payload],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }
}
