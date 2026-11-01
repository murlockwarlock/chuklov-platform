<?php

namespace App\Modules\Analytics\Domain\Enums;

use InvalidArgumentException;

enum PainScoreBasis: string
{
    case Any = 'any';
    case Profile = 'profile';
    case SessionBefore = 'session_before';
    case SessionAfter = 'session_after';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::Any->value => 'Любая оценка',
            self::Profile->value => 'Медицинский профиль',
            self::SessionBefore->value => 'До сеанса',
            self::SessionAfter->value => 'После сеанса',
        ];
    }

    public static function fromInput(mixed $value): self
    {
        if ($value === null || $value === '') {
            return self::Any;
        }
        if ($value instanceof self) {
            return $value;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException('Источник оценки боли указан неверно.');
        }

        return self::tryFrom($value) ?? throw new InvalidArgumentException('Источник оценки боли указан неверно.');
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
