<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;

final class CompanionSafetyClassifier
{
    public function classify(string $text): ?CompanionEscalationReason
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return null;
        }

        if (preg_match('/(?:мне\s+нужен\s+(?:человек|оператор|специалист|врач)|хочу\s+(?:поговорить|связаться|пообщаться)\s+(?:с\s+)?(?:человеком|оператором|специалистом|врачом|евгением)|позови\s+(?:человека|оператора|специалиста|врача|евгения)|соедини\s+с\s+(?:человеком|оператором|специалистом|врачом|евгением)|human\s+(?:agent|support)|talk\s+to\s+(?:a\s+)?(?:human|doctor|specialist))/u', $text) === 1) {
            return CompanionEscalationReason::HumanRequested;
        }

        if (preg_match('/(?:срочно|неотлож|трудно дыш|не могу дышать|сильная боль в груди|emergency|difficulty breathing|chest pain)/u', $text) === 1) {
            return CompanionEscalationReason::UrgentSafetyConcern;
        }

        return null;
    }
}
