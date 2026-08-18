<?php

namespace App\Modules\AI\Domain\Enums;

enum HumanReviewDecision: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case EditedAndAccepted = 'edited_and_accepted';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Принято без изменений',
            self::Rejected => 'Отклонено',
            self::EditedAndAccepted => 'Отредактировано и принято',
        };
    }
}
