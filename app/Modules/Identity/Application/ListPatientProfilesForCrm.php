<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListPatientProfilesForCrm
{
    public function __construct(private OrganizationContext $context, private OrganizationAuthorizer $authorizer) {}

    /** @return Builder<PatientProfile> */
    public function query(User $actor, Client $owner): Builder
    {
        return $this->apply($actor, $owner, PatientProfile::query());
    }

    /** @param Builder<PatientProfile> $query
     * @return Builder<PatientProfile>
     */
    public function apply(User $actor, Client $owner, Builder $query): Builder
    {
        $this->authorizer->authorize($actor, $this->context->organization(), OrganizationPermission::ViewClients);
        if ((int) $owner->organization_id !== $this->context->id()) {
            throw new AuthorizationException;
        }

        return $query->where('organization_id', $this->context->id())
            ->where('owner_client_id', $owner->getKey())->with('clinicalClient');
    }

    /** @return array<int|string, string> */
    public function bookingOptions(User $actor, int $ownerId, string $search = ''): array
    {
        $owner = Client::query()->where('organization_id', $this->context->id())->whereKey($ownerId)->firstOrFail();

        return $this->query($actor, $owner)->whereNull('owner_revoked_at')
            ->where('relationship', '<>', 'SELF')
            ->when(trim($search) !== '', fn (Builder $query) => $query->whereHas('clinicalClient', fn (Builder $clients) => $clients
                ->where('organization_id', $this->context->id())->where('full_name', 'like', '%'.trim($search).'%')))
            ->orderBy('id')->limit(50)->get()->mapWithKeys(fn (PatientProfile $patient): array => [
                $patient->getKey() => (string) ($patient->clinicalClient instanceof Client ? $patient->clinicalClient->full_name : '#'.$patient->getKey()),
            ])->all();
    }

    public function bookingLabel(User $actor, int $ownerId, int $patientId): ?string
    {
        $owner = Client::query()->where('organization_id', $this->context->id())->whereKey($ownerId)->firstOrFail();
        $patient = $this->query($actor, $owner)->whereKey($patientId)->whereNull('owner_revoked_at')->first();

        return $patient === null ? null : (string) ($patient->clinicalClient instanceof Client ? $patient->clinicalClient->full_name : '#'.$patient->getKey());
    }
}
