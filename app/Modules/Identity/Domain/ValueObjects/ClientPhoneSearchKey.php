<?php

namespace App\Modules\Identity\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ClientPhoneSearchKey
{
    private function __construct(public string $value) {}

    public static function from(?string $phone): ?self
    {
        if ($phone === null) {
            return null;
        }

        $formattedPhone = trim($phone);
        $hasExplicitInternationalPrefix = preg_match('/^[^0-9]*\+/', $formattedPhone) === 1;
        $digits = preg_replace('/\D+/u', '', $formattedPhone);

        if (! is_string($digits) || $digits === '' || strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }

        if (! $hasExplicitInternationalPrefix && strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7'.substr($digits, 1);
        }

        if (strlen($digits) === 11 && $digits[0] === '7') {
            return new self($digits);
        }

        if ($digits[0] === '0') {
            return new self($digits);
        }

        if ($digits[0] < '1' || $digits[0] > '9') {
            throw new InvalidArgumentException('The phone search key is invalid.');
        }

        return new self($digits);
    }
}
