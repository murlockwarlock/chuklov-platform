<?php

namespace App\Modules\Channels\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class NotificationActionButton
{
    public function __construct(
        public string $text,
        public ?string $url = null,
        public ?string $callbackData = null,
        public ?string $webAppUrl = null,
    ) {
        if (trim($this->text) === '' || mb_strlen($this->text) > 64) {
            throw new InvalidArgumentException('The notification button label is invalid.');
        }

        if (count(array_filter([$this->url, $this->callbackData, $this->webAppUrl], static fn (?string $target): bool => $target !== null)) !== 1) {
            throw new InvalidArgumentException('A notification button must contain exactly one action target.');
        }

        if ($this->callbackData !== null) {
            if (mb_strlen($this->callbackData) > 64 || preg_match('/^[A-Za-z0-9:_-]+$/', $this->callbackData) !== 1) {
                throw new InvalidArgumentException('The notification callback is invalid.');
            }

            return;
        }

        if ($this->url !== null && $this->telegramProfileUrl($this->url)) {
            return;
        }

        $url = $this->url ?? $this->webAppUrl;
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('The notification button URL is invalid.');
        }

        $parts = parse_url($url);
        $allowedSchemes = $this->webAppUrl === null ? ['http', 'https'] : ['https'];
        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), $allowedSchemes, true)
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
