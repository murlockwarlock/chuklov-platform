<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use Carbon\CarbonImmutable;

final class PaymentPreVisitBookingEligibility
{
    public function handle(ScenarioEvaluationContext $context): bool
    {
        $obligation = $context->obligation;
        $booking = $context->booking;

        if ($obligation === null || $booking === null
            || $obligation->booking_id === null
            || $obligation->purchase_id !== null
            || (int) $obligation->organization_id !== (int) $context->event->organization_id
            || (int) $booking->organization_id !== (int) $context->event->organization_id
            || (int) $booking->getKey() !== (int) $obligation->booking_id
            || in_array($booking->status->value, BookingStatus::terminalValues(), true)) {
            return false;
        }

        return $booking->startsAtUtc()->greaterThan(CarbonImmutable::parse((string) $context->event->occurred_at)->utc());
    }
}
