<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListClientSessions
{
    public function __construct(private MedicalSessionAuthorization $authorization) {}

    /**
     * @param  Builder<MedicalSession>  $query
     * @return Builder<MedicalSession>
     */
    public function apply(User $actor, Client $client, Builder $query): Builder
    {
        $organization = $this->authorization->authorizeViewClient($actor, $client);
        $orgId = (int) $organization->getKey();
        $clientId = (int) $client->getKey();

        return $query
            ->where('organization_id', $orgId)
            ->where('client_id', $clientId)
            ->select([
                'id',
                'organization_id',
                'client_id',
                'specialist_id',
                'booking_id',
                'occurred_at',
            ])
            ->with([
                'specialist:id,organization_id,display_name',
                'booking:id,organization_id,starts_at,status',
            ])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    /** @return Builder<MedicalSession> */
    public function query(User $actor, Client $client): Builder
    {
        return $this->apply($actor, $client, $client->sessions()->getQuery());
    }
}
