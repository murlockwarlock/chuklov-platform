<?php

namespace Tests\Unit\ClientCompanion;

use App\Modules\ClientCompanion\Application\Services\CompanionSafetyClassifier;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use PHPUnit\Framework\TestCase;

final class CompanionSafetyClassifierTest extends TestCase
{
    public function test_normal_specialist_question_is_not_automatically_handed_off(): void
    {
        self::assertNull((new CompanionSafetyClassifier)->classify('Какой специалист может помочь подобрать массаж?'));
    }

    public function test_explicit_request_to_speak_with_evgeniy_is_handed_off(): void
    {
        self::assertSame(
            CompanionEscalationReason::HumanRequested,
            (new CompanionSafetyClassifier)->classify('Хочу поговорить с Евгением лично.'),
        );
    }

    public function test_urgent_safety_message_is_handed_off(): void
    {
        self::assertSame(
            CompanionEscalationReason::UrgentSafetyConcern,
            (new CompanionSafetyClassifier)->classify('Сильная боль в груди, трудно дышать.'),
        );
    }
}
