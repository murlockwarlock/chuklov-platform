<?php

namespace App\Modules\Scheduling\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class LocalDate
{
    private function __construct(public string $value) {}

    public static function from(string $value): self
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('The date must use the YYYY-MM-DD format.');
        }

        return new self($value);
    }

    public function weekday(): int
    {
        return (int) (new DateTimeImmutable($this->value, new DateTimeZone('UTC')))->format('N');
    }

    public function nextDay(): self
    {
        return self::from((new DateTimeImmutable($this->value, new DateTimeZone('UTC')))
            ->modify('+1 day')
            ->format('Y-m-d'));
    }
}
