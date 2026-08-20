<?php

namespace App\Modules\Finance\Application;

use App\Models\User;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Scheduling\Domain\Models\Booking;
use UnexpectedValueException;

final class GetBookingFinanceSummary
{
    public function __construct(
        private readonly FinanceAuthorization $authorization,
        private readonly ReconcileFinancialObligation $reconciliation,
    ) {}

    public function handle(User $actor, Booking $booking): ?BookingFinanceSummary
    {
        $organization = $this->authorization->authorizeView($actor);
        $this->authorization->assertOwned($booking);
        $obligation = FinancialObligation::query()
            ->where('organization_id', $organization->getKey())
            ->where('booking_id', $booking->getKey())
            ->with(['client', 'service', 'booking.service'])
            ->first();

        if ($obligation === null) {
            return null;
        }

        try {
            $reconciliation = $this->reconciliation->handle(
                (int) $organization->getKey(),
                (int) $obligation->getKey(),
            );
        } catch (UnexpectedValueException) {
            $reconciliation = null;
        }

        return new BookingFinanceSummary($obligation, $reconciliation);
    }
}
