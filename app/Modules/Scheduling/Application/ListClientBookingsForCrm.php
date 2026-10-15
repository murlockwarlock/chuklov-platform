<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Domain\Models\Booking;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListClientBookingsForCrm
{
    public function __construct(private BookingAuthorization $authorization) {}

    /**
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public function apply(User $actor, Client $client, Builder $query): Builder
    {
        $this->authorization->authorizeViewClient($actor, $client);

        return $query
            ->where('organization_id', $client->organization_id)
            ->where(fn (Builder $query) => $query->where('client_id', $client->getKey())
                ->orWhereHas('patientProfile', fn (Builder $patients) => $patients->where('organization_id', $client->organization_id)
                    ->where(fn (Builder $identities) => $identities->where('clinical_client_id', $client->getKey())
                        ->orWhere('linked_account_client_id', $client->getKey()))))
            ->select([
                'id',
                'organization_id',
                'client_id',
                'patient_profile_id',
                'specialist_id',
                'service_id',
                'visit_format',
                'status',
                'payment_status',
                'payment_requirement',
                'starts_at',
                'ends_at',
                'schedule_timezone',
                'location',
                'working_location_id',
                'location_area',
                'location_snapshot',
                'meeting_link_mode',
                'meeting_url',
                'party_size',
                'event_version',
                'requested_at',
            ])
            ->with([
                'client:id,organization_id,full_name',
                'specialist:id,organization_id,display_name',
                'service:id,organization_id,name',
                'patientProfile.clinicalClient:id,organization_id,full_name',
            ])
            ->orderByDesc('starts_at')
            ->orderByDesc('id');
    }

    /** @return Builder<Booking> */
    public function query(User $actor, Client $client): Builder
    {
        return $this->apply($actor, $client, Booking::query());
    }
}
