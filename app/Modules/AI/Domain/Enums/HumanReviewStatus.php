<?php

namespace App\Modules\AI\Domain\Enums;

enum HumanReviewStatus: string
{
    case NotRequired = 'not_required';
    case PendingReview = 'pending_review';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case EditedAndAccepted = 'edited_and_accepted';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Не требуется',
            self::PendingReview => 'Ожидает проверки специалиста',
            self::Accepted => 'Принято специалистом',
            self::Rejected => 'Отклонено специалистом',
            self::EditedAndAccepted => 'Отредактировано и принято',
        };
    }
}
