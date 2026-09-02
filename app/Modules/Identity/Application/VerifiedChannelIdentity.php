<?php

namespace App\Modules\Identity\Application;

class VerifiedChannelIdentity
{
    public readonly ?string $username;

    public function __construct(
        public readonly string $channel,
        public readonly string $externalId,
        public readonly string $displayName,
        public readonly string $language,
        public readonly ?string $startParameter = null,
        ?string $username = null,
    ) {
        $this->username = self::normalizeUsername($username);
    }

    public static function normalizeUsername(?string $username): ?string
    {
        $username = trim((string) $username);
        if (str_starts_with($username, '@')) {
            $username = substr($username, 1);
        }

        return $username !== '' && preg_match('/^[A-Za-z0-9_]{5,32}$/', $username) === 1
            ? $username
            : null;
    }
}
