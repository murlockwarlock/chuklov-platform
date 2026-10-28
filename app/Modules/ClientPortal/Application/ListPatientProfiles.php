<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Identity\Application\EnsureSelfPatientProfile;
use App\Modules\Identity\Application\PatientProfileAccess;
use App\Modules\Identity\Application\PatientProfileProjection;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientAccessGrant;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final readonly class ListPatientProfiles
{
    public function __construct(private OrganizationContext $context, private PatientProfileAccess $access,
        private EnsureSelfPatientProfile $selfProfiles, private PatientProfileProjection $projection, private OrganizationFeatureGate $features) {}

    /** @return array<string, mixed> */
    public function page(Client $account): array
    {
        return $this->query($account, includeRevoked: true)->orderBy('id')->paginate(20)
            ->through(fn (PatientProfile $patient): array => $this->projection->handle($patient, $account))->toArray();
    }

    /** @return array{items: list<array<string, mixed>>, hasMore: bool, enabled: bool} */
    public function forBooking(Client $account, ?int $selectedId = null): array
    {
        if ((int) $account->organization_id !== $this->context->id()) {
            throw new AuthorizationException;
        }
        if (! $this->features->isEnabled($this->context->organization(), OrganizationFeature::ClientRecords)) {
            if ($selectedId !== null) {
                PatientProfile::query()->where('organization_id', $this->context->id())->where('clinical_client_id', $account->getKey())
                    ->where('relationship', 'SELF')->whereKey($selectedId)->firstOrFail();
            }

            return ['items' => [], 'hasMore' => false, 'enabled' => false];
        }
        $profiles = $this->query($account)->orderBy('id')->limit(51)->get();
        $hasMore = $profiles->count() > 50;
        $profiles = $profiles->take(50)->values();
        if ($selectedId !== null && ! $profiles->contains('id', $selectedId)) {
            $profiles->push($this->query($account)->whereKey($selectedId)->firstOrFail());
        }

        $items = $profiles->map(fn (PatientProfile $patient): array => $this->projection->handle($patient, $account))->values()->all();

        return ['items' => array_values($items), 'hasMore' => $hasMore, 'enabled' => true];
    }

    /** @return Builder<PatientProfile> */
    private function query(Client $account, bool $includeRevoked = false): Builder
    {
        $this->access->authorizeAccount($account);
        $this->selfProfiles->handle($account);
        $patientTable = (new PatientProfile)->getTable();
        $grantTable = (new PatientAccessGrant)->getTable();

        return PatientProfile::query()->where($patientTable.'.organization_id', $this->context->id())
            ->where(fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('owner_client_id', $account->getKey())->when(! $includeRevoked, fn (Builder $owned) => $owned->whereNull('owner_revoked_at')))
                ->orWhere('linked_account_client_id', $account->getKey())
                ->orWhereExists(fn ($grants): mixed => $grants
                    ->from($grantTable)
                    ->whereColumn($grantTable.'.patient_profile_id', $patientTable.'.id')
                    ->where($grantTable.'.organization_id', $this->context->id())
                    ->where($grantTable.'.grantee_client_id', $account->getKey())
                    ->where($grantTable.'.scope', 'medical_records')
                    ->whereNull($grantTable.'.revoked_at')
                    ->whereNull($patientTable.'.owner_revoked_at')))
            ->with(['clinicalClient:id,organization_id,full_name', 'accessGrants' => fn ($grants) => $grants
                ->where('grantee_client_id', $account->getKey())
                ->whereNull('revoked_at')
                ->with('grantee:id,organization_id,full_name')]);
    }
}
