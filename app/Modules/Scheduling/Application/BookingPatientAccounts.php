<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Domain\Models\Booking;
use Illuminate\Support\Collection;

final class BookingPatientAccounts
{
    /** @return Collection<int, Client> */
    public function handle(Booking $booking): Collection
    {
        $ids = [(int) $booking->client_id];
        $linkedId = $booking->patientProfile?->linked_account_client_id;
        if ($linkedId !== null) {
            $ids[] = (int) $linkedId;
        }

        return Client::query()->where('organization_id', $booking->organization_id)->whereIn('id', array_unique($ids))
            ->get(['id', 'organization_id', 'full_name', 'language', 'timezone']);
    }

    public function canCompleteMedicalProfile(Booking $booking, int $accountId): bool
    {
        return $accountId === $booking->clinicalClientId()
            || ($booking->patient_profile_id !== null && (int) $booking->patientProfile?->linked_account_client_id === $accountId);
    }

    public function includes(Booking $booking, int $accountId): bool
    {
        return (int) $booking->client_id === $accountId
            || ($booking->patient_profile_id !== null && (int) $booking->patientProfile?->linked_account_client_id === $accountId);
    }
}
