<?php

namespace App\Modules\ClientCompanion\Domain\Enums;

enum CompanionEscalationReason: string
{
    case HumanRequested = 'human_requested';
    case OutOfScope = 'out_of_scope';
    case UrgentSafetyConcern = 'urgent_safety_concern';
    case RepeatedExecutionFailure = 'repeated_execution_failure';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::HumanRequested => 'Клиент попросил специалиста',
            self::OutOfScope => 'Вопрос требует специалиста',
            self::UrgentSafetyConcern => 'Требуется внимание специалиста',
            self::RepeatedExecutionFailure => 'AI временно недоступен',
            self::Other => 'Другое обращение к специалисту',
        };
    }
}
