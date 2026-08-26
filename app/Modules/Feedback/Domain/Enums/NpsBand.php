<?php

namespace App\Modules\Feedback\Domain\Enums;

use InvalidArgumentException;

enum NpsBand: string
{
    case Positive = 'positive';
    case Internal = 'internal';

    public static function fromScore(int $score, int $positiveThreshold): self
    {
        if ($score < 1 || $score > 10 || $positiveThreshold < 1 || $positiveThreshold > 10) {
            throw new InvalidArgumentException('The NPS score or threshold is invalid.');
        }

        return $score >= $positiveThreshold ? self::Positive : self::Internal;
    }
}
