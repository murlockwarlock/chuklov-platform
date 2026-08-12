<?php

namespace App\Modules\Identity\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class NormalizedEmail
{
    private function __construct(public string $value) {}

    public static function from(string $email): self
    {
        $normalized = mb_strtolower(trim($email));

        if ($normalized === ''
            || mb_strlen($normalized) > 320
            || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The email address is invalid.');
        }

        return new self($normalized);
    }
}
