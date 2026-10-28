<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Identity\Application\ResolvePatientClinicalClient;
use App\Modules\Scheduling\Application\ListClientBookings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class ResolvePortalMedicalPatient
{
    public function __construct(private ClientPortalContext $context, private ResolvePatientClinicalClient $patients, private ListClientBookings $bookings) {}

    public function handle(?int $patientId, ?int $bookingId): ?int
    {
        if ($bookingId === null) {
            $this->patients->handle($this->context->client(), $patientId);

            return $patientId;
        }
        $booking = $this->bookings->find($bookingId);
        if ($booking === null) {
            throw new ModelNotFoundException;
        }
        $patientId ??= $booking->patient_profile_id;
        $subject = $this->patients->handle($this->context->client(), $patientId);
        if ((int) $subject->getKey() !== $booking->clinicalClientId()) {
            throw new AuthorizationException;
        }

        return $patientId;
    }
}
