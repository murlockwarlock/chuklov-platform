<?php

namespace App\Modules\Channels\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class NotificationActionButton
{
    public function __construct(
        public string $text,
        public string $url,
    ) {
        if (trim($this->text) === '' || mb_strlen($this->text) > 64) {
            throw new InvalidArgumentException('The notification button label is invalid.');
        }

        if ($this->telegramProfileUrl($this->url)) {
            return;
        }

        if (filter_var($this->url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('The notification button URL is invalid.');
        }

        $parts = parse_url($this->url);
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || trim($parts['host']) === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)) {
            throw new InvalidArgumentException('The notification button URL is invalid.');
        }
    }

    private function telegramProfileUrl(string $url): bool
    {
        return preg_match('/\Atg:\/\/user\?id=[1-9][0-9]{0,19}\z/', $url) === 1;
    }
}
