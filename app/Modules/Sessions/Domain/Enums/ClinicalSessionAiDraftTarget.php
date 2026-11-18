<?php

namespace App\Modules\Sessions\Domain\Enums;

enum ClinicalSessionAiDraftTarget: string
{
    case Result = 'result';
    case RootCauseHypothesis = 'root_cause_hypothesis';
    case ProtocolSteps = 'protocol_steps';
    case RecommendationDraft = 'recommendation_draft';

    public function label(): string
    {
        return match ($this) {
            self::Result => 'Результат после сеанса',
            self::RootCauseHypothesis => 'Гипотеза первопричины для проверки',
            self::ProtocolSteps => 'Протокол сеанса',
            self::RecommendationDraft => 'Рекомендации специалиста',
        };
    }

    public function isAvailable(): bool
    {
        return $this === self::Result;
    }

    public function unavailableReason(): ?string
    {
        return $this->isAvailable()
            ? null
            : 'Ожидает утверждённого клинического алгоритма и prompt.';
    }
}
