<?php

namespace App\Modules\Scenarios\Application;

use App\Modules\Scenarios\Domain\ValueObjects\ScenarioEvaluationContext;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use Carbon\CarbonImmutable;

final class HasQualifyingNextBooking
{
    public function forScenario(ScenarioEvaluationContext $context): bool
    {
        if ($context->booking === null || $context->client === null) {
            return false;
        }

        return $this->forBooking(
            $context->booking,
            CarbonImmutable::parse((string) $context->event->occurred_at)->utc(),
        );
    }

    public function forBooking(Booking $booking, CarbonImmutable $after): bool
    {
        return Booking::query()
            ->where('organization_id', $booking->organization_id)
            ->where('client_id', $booking->client_id)
            ->whereIn('status', BookingStatus::qualifyingFutureValues())
            ->where('starts_at', '>', $after->utc())
            ->exists();
    }

    /** @param iterable<Booking> $bookings
     * @param  array<int, CarbonImmutable>  $referenceTimes
     * @return array<int, bool>
     */
    public function forCompletedBookings(iterable $bookings, array $referenceTimes): array
    {
        $bookings = collect($bookings);
        if ($bookings->isEmpty()) {
            return [];
        }

        $organizationId = (int) $bookings->first()->organization_id;
        $clientIds = $bookings->pluck('client_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values();
        $after = null;
        foreach ($referenceTimes as $referenceTime) {
            if ($after === null || $referenceTime->lessThan($after)) {
                $after = $referenceTime;
            }
        }

        $futureBookingsQuery = Booking::query()
            ->where('organization_id', $organizationId)
            ->whereIn('client_id', $clientIds)
            ->whereIn('status', BookingStatus::qualifyingFutureValues());
        if ($after instanceof CarbonImmutable) {
            $futureBookingsQuery->where('starts_at', '>', $after->utc());
        }
        $futureBookings = $futureBookingsQuery->get(['client_id', 'starts_at']);

        $result = [];
        foreach ($bookings as $booking) {
            $reference = $referenceTimes[(int) $booking->getKey()] ?? null;
            $result[(int) $booking->getKey()] = $reference instanceof CarbonImmutable
                && $futureBookings->contains(fn (Booking $future): bool => (int) $future->client_id === (int) $booking->client_id
                    && CarbonImmutable::parse((string) $future->starts_at)->utc()->greaterThan($reference));
        }

        return $result;
    }
}
