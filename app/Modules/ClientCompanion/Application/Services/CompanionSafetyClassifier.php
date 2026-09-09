<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;

final class CompanionSafetyClassifier
{
    public function classify(string $text): ?CompanionEscalationReason
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/(?:срочно|неотлож|трудно дыш|не могу дышать|сильная боль в груди|emergency|difficulty breathing|chest pain)/u', $text) === 1) {
            return CompanionEscalationReason::UrgentSafetyConcern;
        }

        if ($this->isExplicitHumanNegation($text)) {
            return null;
        }

        if (preg_match('/(?:мне\s+нужен\s+(?:человек|оператор|специалист|врач)|хочу\s+(?:поговорить|связаться|пообщаться)\s+(?:с\s+)?(?:человеком|оператором|специалистом|врачом|евгением)|позови\s+(?:человека|оператора|специалиста|врача|евгения)|соедини\s+(?:меня\s+)?с\s+(?:человеком|оператором|специалистом|врачом|евгением)|human\s+(?:agent|support)|talk\s+to\s+(?:a\s+)?(?:human|doctor|specialist))/u', $text) === 1) {
            return CompanionEscalationReason::HumanRequested;
        }

        return null;
    }

    public function isHandoffForbidden(string $text): bool
    {
        $normalized = $this->normalize($text);

        return $this->isExplicitHumanNegation($normalized)
            || in_array($normalized, ['привет', 'привет, ты кто?', 'привет ты кто'], true);
    }

    public function isExplicitHumanNegation(string $text): bool
    {
        $text = $this->normalize($text);

        return preg_match('/(?<!\p{L})(?:не\s+(?:нужен|нужна|надо)\s+(?:мне\s+)?(?:человек(?:а|у)?|оператор(?:а|у)?|специалист(?:а|у)?|врач(?:а|у)?)|(?:мне\s+)?не\s+(?:нужен|нужна)\s+(?:человек(?:а|у)?|оператор(?:а|у)?|специалист(?:а|у)?|врач(?:а|у)?)|не\s+подключай\s+(?:мне\s+)?(?:человек(?:а|у)?|оператор(?:а|у)?|специалист(?:а|у)?|врач(?:а|у)?))/u', $text) === 1;
    }

    private function normalize(string $text): string
    {
        return preg_replace('/\s+/u', ' ', mb_strtolower(trim($text))) ?? '';
    }
}
