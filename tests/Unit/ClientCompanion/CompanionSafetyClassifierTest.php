<?php

namespace Tests\Unit\ClientCompanion;

use App\Modules\ClientCompanion\Application\Services\CompanionSafetyClassifier;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use PHPUnit\Framework\TestCase;

final class CompanionSafetyClassifierTest extends TestCase
{
    public function test_only_explicit_requests_to_speak_with_a_human_are_handed_off(): void
    {
        $classifier = new CompanionSafetyClassifier;

        foreach (['Привет, ты кто?', 'Кто такой Евгений?', 'Расскажи про специалиста', 'Я массажист', 'привет', 'привет, не надо мне специалиста', 'мне не нужен специалист', 'не подключай человека'] as $message) {
            self::assertNull($classifier->classify($message), $message);
        }

        foreach (['Мне нужен специалист', 'Хочу поговорить с Евгением', 'Соедини меня с человеком'] as $message) {
            self::assertSame(CompanionEscalationReason::HumanRequested, $classifier->classify($message), $message);
        }
    }

    public function test_urgent_safety_message_is_handed_off(): void
    {
        self::assertSame(
            CompanionEscalationReason::UrgentSafetyConcern,
            (new CompanionSafetyClassifier)->classify('Сильная боль в груди, трудно дышать.'),
        );
    }
}
