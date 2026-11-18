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
    public const MAX_HISTORY = 10;

    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private GetSession $getSession,
    ) {}

    public function handle(User $actor, MedicalSession $session, Client $client, int $historyLimit = self::MAX_HISTORY): SessionDynamicsData
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

        $historyLimit = max(1, min(self::MAX_HISTORY, $historyLimit));
        $previousSessions = MedicalSession::query()
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
            ->limit($historyLimit - 1)
            ->select([
                'id',
                'organization_id',
                'client_id',
                'specialist_id',
                'booking_id',
                'occurred_at',
                'encryption_key_version',
            ])
            ->with([
                'specialist:id,organization_id,display_name',
                'booking:id,organization_id,starts_at',
            ])
            ->get();

        $previous = $previousSessions->first();
        $history = [[
            'period' => 'Текущий сеанс',
            'data' => $current,
            'specialist' => $session->specialist->display_name,
            'booking' => self::bookingLabel($session, $organization->defaultTimezone()),
        ]];

        foreach ($previousSessions as $index => $previousSession) {
            $data = $this->getSession->handle($actor, $previousSession, $client);
            if ($data === null) {
                continue;
            }

            $history[] = [
                'period' => $index === 0
                    ? 'Предыдущий сеанс'
                    : __('Сеанс :number назад', ['number' => $index + 1]),
                'data' => $data,
                'specialist' => $previousSession->specialist->display_name,
                'booking' => self::bookingLabel($previousSession, $organization->defaultTimezone()),
            ];
        }

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
            history: $history,
        );
    }

    private static function bookingLabel(MedicalSession $session, string $timezone = 'UTC'): string
    {
        if ($session->booking === null) {
            return 'Без записи на приём';
        }

        return Carbon::parse((string) $session->booking->getAttribute('starts_at'), 'UTC')
            ->setTimezone($timezone)
            ->format('d.m.Y H:i');
    }
}
