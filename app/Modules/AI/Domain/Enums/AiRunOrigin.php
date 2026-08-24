<?php

namespace App\Modules\AI\Domain\Enums;

enum AiRunOrigin: string
{
    case User = 'user';
    case SystemScenario = 'system_scenario';
    case Playground = 'playground';
    case Evaluation = 'evaluation';
    case ClientPortal = 'client_portal';
    case ClientCompanion = 'client_companion';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Сотрудник (CRM)',
            self::SystemScenario => 'Системный сценарий',
            self::Playground => 'Песочница (Playground)',
            self::Evaluation => 'Тестирование (Evaluation)',
            self::ClientPortal => 'Клиентский портал',
            self::ClientCompanion => 'Клиентский AI-компаньон',
        };
    }
}
