<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\DTOs\SessionDynamicsData;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final readonly class GetSessionDynamics
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private GetSession $getSession,
    ) {}

    public function handle(User $actor, MedicalSession $session, Client $client): SessionDynamicsData
    {
        $organization = $this->authorization->authorizeView($actor, $session, $client);
        $current = $this->getSession->handle($actor, $session, $client);

        if (! $current instanceof MedicalSessionData) {
            abort(404);
        }

        $session->loadMissing([
            'specialist:id,organization_id,display_name',
            'booking:id,organization_id,starts_at',
        ]);

        $previous = MedicalSession::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where(function (Builder $query) use ($session): void {
                $query->where('occurred_at', '<', $session->occurred_at)
                    ->orWhere(function (Builder $query) use ($session): void {
                        $query->where('occurred_at', $session->occurred_at)
                            ->where('id', '<', $session->getKey());
                    });
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(1)
            ->with([
                'specialist:id,organization_id,display_name',
                'booking:id,organization_id,starts_at',
            ])
            ->first();

        return new SessionDynamicsData(
            current: $current,
            previous: $previous instanceof MedicalSession
                ? $this->getSession->handle($actor, $previous, $client)
                : null,
            currentSpecialist: $session->specialist->display_name,
            currentBooking: self::bookingLabel($session, $organization->defaultTimezone()),
            previousSpecialist: $previous?->specialist->display_name,
            previousBooking: $previous instanceof MedicalSession ? self::bookingLabel($previous, $organization->defaultTimezone()) : null,
            timezone: $organization->defaultTimezone(),
        );
    }

    private static function bookingLabel(MedicalSession $session, string $timezone = 'UTC'): string
    {
        if ($session->booking === null) {
            return 'Без записи на приём';
        }

        return Carbon::parse((string) $session->booking->getAttribute('starts_at'), 'UTC')
            ->setTimezone($timezone)
            ->format('d.m.Y H:i')
            .' (#'.$session->booking->getKey().')';
    }
}
