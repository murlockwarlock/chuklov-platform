<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;

final readonly class BookingMedicalProfileConditionEvaluator implements ScenarioConditionEvaluator
{
    public function __construct(private BookingNeedsMedicalProfile $medicalProfile) {}

    public function type(): string
    {
        return 'booking.needs_medical_profile';
    }

    public function validate(ScenarioCondition $condition): void
    {
        BooleanScenarioCondition::values($condition);
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        $booking = $context->booking;
        $client = $context->client;
        if ($booking === null || $client === null
            || (int) $booking->organization_id !== (int) $context->event->organization_id
            || (int) $client->organization_id !== (int) $context->event->organization_id
            || (int) $booking->client_id !== (int) $client->getKey()) {
            return false;
        }

        $actual = $this->medicalProfile->needsCompletion($booking);
        $values = BooleanScenarioCondition::values($condition);

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === $values[0],
            ScenarioConditionOperator::NotEquals => $actual !== $values[0],
            ScenarioConditionOperator::In => in_array($actual, $values, true),
            ScenarioConditionOperator::Exists => $actual,
        };
    }
}
