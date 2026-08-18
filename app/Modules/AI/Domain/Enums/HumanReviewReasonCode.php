<?php

namespace App\Modules\AI\Domain\Enums;

enum HumanReviewReasonCode: string
{
    case SpecialistConfirmed = 'specialist_confirmed';
    case SpecialistRejected = 'specialist_rejected';
    case SpecialistEdited = 'specialist_edited';
    case MissingContext = 'missing_context';
    case IncorrectContent = 'incorrect_content';
    case UnsafeContent = 'unsafe_content';

    public function label(): string
    {
        return match ($this) {
            self::SpecialistConfirmed => 'Подтверждено специалистом',
            self::SpecialistRejected => 'Отклонено специалистом',
            self::SpecialistEdited => 'Отредактировано специалистом',
            self::MissingContext => 'Недостаточно контекста',
            self::IncorrectContent => 'Некорректное содержание',
            self::UnsafeContent => 'Небезопасное содержание',
        };
    }
}
