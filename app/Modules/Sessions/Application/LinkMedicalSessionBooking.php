<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class LinkMedicalSessionBooking
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, MedicalSession $session, Client $client, ?int $bookingId): MedicalSession
    {
        $organization = $this->authorization->authorizeManageSession($actor, $session, $client);
        $organizationId = (int) $organization->getKey();

        return DB::transaction(function () use ($actor, $organization, $organizationId, $session, $client, $bookingId): MedicalSession {
            $lockedSession = MedicalSession::query()
                ->where('organization_id', $organizationId)
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $booking = null;
            if ($bookingId !== null) {
                if ($bookingId <= 0) {
                    throw ValidationException::withMessages([
                        'booking_id' => 'Выберите корректную запись на приём.',
                    ]);
                }

                $booking = Booking::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($bookingId)
                    ->lockForUpdate()
                    ->first();

                if ($booking === null) {
                    throw ValidationException::withMessages([
                        'booking_id' => 'Выбранная запись на приём недоступна.',
                    ]);
                }

                if ($booking->clinicalClientId() !== (int) $lockedSession->client_id
                    || (int) $booking->specialist_id !== (int) $lockedSession->specialist_id) {
                    throw ValidationException::withMessages([
                        'booking_id' => 'Запись должна относиться к этому клиенту и специалисту сеанса.',
                    ]);
                }
            }

            $previousBookingId = $lockedSession->booking_id === null ? null : (int) $lockedSession->booking_id;
            if ($previousBookingId === $bookingId) {
                return $lockedSession;
            }

            $lockedSession->forceFill(['booking_id' => $bookingId]);
            $lockedSession->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'medical.session.booking_link.updated',
                targetType: MedicalSession::class,
                targetId: (string) $lockedSession->getKey(),
                metadata: [
                    'previous_booking_id' => $previousBookingId,
                    'booking_id' => $bookingId,
                    'client_id' => (int) $client->getKey(),
                    'specialist_id' => (int) $lockedSession->specialist_id,
                ],
            );

            return $lockedSession;
        });
    }
}
