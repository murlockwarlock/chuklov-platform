<?php

namespace App\Modules\Channels\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class CompanionActionButton
{
    public function __construct(
        public string $text,
        public ?string $callbackData = null,
        public ?string $url = null,
    ) {
        if (trim($this->text) === '' || mb_strlen($this->text) > 64) {
            throw new InvalidArgumentException('The Companion button label is invalid.');
        }
        if (($this->callbackData === null) === ($this->url === null)) {
            throw new InvalidArgumentException('A Companion button must have one safe action target.');
        }
        if ($this->callbackData !== null && (mb_strlen($this->callbackData) > 64 || preg_match('/^[A-Za-z0-9:_-]+$/', $this->callbackData) !== 1)) {
            throw new InvalidArgumentException('The Companion callback is invalid.');
        }
        if ($this->url !== null && (filter_var($this->url, FILTER_VALIDATE_URL) === false || ! preg_match('/^https:\/\//i', $this->url))) {
            throw new InvalidArgumentException('The Companion button URL is invalid.');
        }
    }
}
