<?php

namespace App\Modules\Organizations\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class IanaTimezone
{
    private function __construct(public string $value) {}

    public static function from(string $timezone): self
    {
        $timezone = trim($timezone);

        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('The timezone must be an IANA timezone identifier.');
        }

        return new self($timezone);
    }
}
