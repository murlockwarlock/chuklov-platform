<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Domain\Enums\PatientNotificationRecipientPolicy;
use App\Modules\Scheduling\Domain\Models\Booking;
use Illuminate\Support\Collection;

final readonly class ResolveBookingNotificationRecipients
{
    public function __construct(private GetPatientNotificationRecipientPolicy $policy) {}

    /** @return Collection<int, Client> */
    public function handle(Booking $booking): Collection
    {
        $booking->loadMissing('patientProfile');
        $bookerId = (int) $booking->client_id;
        $patientId = (int) ($booking->patientProfile?->linked_account_client_id ?? $booking->client_id);
        $recipientIds = match ($this->policy->handle()) {
            PatientNotificationRecipientPolicy::PatientOnly => [$patientId],
            PatientNotificationRecipientPolicy::BookerOnly => [$bookerId],
            PatientNotificationRecipientPolicy::Both => [$bookerId, $patientId],
        };

        return Client::query()
            ->where('organization_id', $booking->organization_id)
            ->whereIn('id', array_values(array_unique($recipientIds)))
            ->get(['id', 'organization_id', 'full_name', 'language', 'timezone']);
    }
}
