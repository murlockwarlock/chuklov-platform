<?php

namespace App\Modules\Content\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class ContentExternalImageUrl
{
    public const MAX_LENGTH = 2000;

    private function __construct(public string $value) {}

    public static function from(mixed $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The external content image URL is invalid.');
        }

        $value = trim($value);

        if (! self::isValid($value)) {
            throw new InvalidArgumentException('The external content image URL is invalid.');
        }

        return new self($value);
    }

    public static function required(mixed $value): self
    {
        return self::from($value) ?? throw new InvalidArgumentException('The external content image URL is invalid.');
    }

    private static function isValid(string $value): bool
    {
        if ($value === ''
            || mb_strlen($value) > self::MAX_LENGTH
            || filter_var($value, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }

        $parts = parse_url($value);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = is_string($parts['scheme'] ?? null) ? strtolower($parts['scheme']) : null;
        $host = is_string($parts['host'] ?? null) ? trim($parts['host'], '[]') : null;

        if ($scheme !== 'https'
            || $host === ''
            || $host === null
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
        ) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
