<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\Contracts\ScenarioConditionEvaluator;
use App\Modules\Scenarios\Domain\Enums\ScenarioConditionOperator;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioCondition;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;

final class BookingNextBookingConditionEvaluator implements ScenarioConditionEvaluator
{
    public function type(): string
    {
        return 'booking.has_qualifying_next_booking';
    }

    public function validate(ScenarioCondition $condition): void
    {
        BooleanScenarioCondition::values($condition);
    }

    public function evaluate(ScenarioCondition $condition, ScenarioEvaluationContext $context): bool
    {
        $actual = $this->hasQualifyingNextBooking($context);

        return match ($condition->operator) {
            ScenarioConditionOperator::Equals => $actual === BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::NotEquals => $actual !== BooleanScenarioCondition::value($condition->value),
            ScenarioConditionOperator::In => in_array($actual, BooleanScenarioCondition::values($condition), true),
            ScenarioConditionOperator::Exists => $actual,
        };
    }

    private function hasQualifyingNextBooking(ScenarioEvaluationContext $context): bool
    {
        if ($context->booking === null || $context->client === null) {
            return false;
        }

        $query = Booking::query()
            ->where('organization_id', $context->event->organization_id)
            ->where('client_id', $context->client->getKey())
            ->whereIn('status', BookingStatus::qualifyingFutureValues())
            ->where('starts_at', '>', CarbonImmutable::parse((string) $context->event->occurred_at)->utc());

        if ($context->evaluationEndsAt !== null) {
            $query->where('starts_at', '<=', $context->evaluationEndsAt->utc());
        }

        return $query->exists();
    }
}
