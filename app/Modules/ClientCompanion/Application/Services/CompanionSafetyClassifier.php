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

        if (preg_match('/\b(?:человек|оператор|специалист|врач|human|doctor)\b/u', $text) === 1) {
            return CompanionEscalationReason::HumanRequested;
        }

        if (preg_match('/(?:срочно|неотлож|трудно дыш|не могу дышать|сильная боль в груди|emergency|difficulty breathing|chest pain)/u', $text) === 1) {
            return CompanionEscalationReason::UrgentSafetyConcern;
        }

        return null;
    }
}
