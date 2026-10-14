<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\Identity\Application\EnsureSelfPatientProfile;
use App\Modules\Identity\Application\PatientProfileAccess;
use App\Modules\Identity\Application\PatientProfileProjection;
use App\Modules\Identity\Domain\Models\Client;
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

        return ['items' => $profiles->map(fn (PatientProfile $patient): array => $this->projection->handle($patient, $account))->values()->all(), 'hasMore' => $hasMore, 'enabled' => true];
    }

    /** @return Builder<PatientProfile> */
    private function query(Client $account, bool $includeRevoked = false): Builder
    {
        $this->access->authorizeAccount($account);
        $this->selfProfiles->handle($account);

        return PatientProfile::query()->where('organization_id', $this->context->id())
            ->where(fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('owner_client_id', $account->getKey())->when(! $includeRevoked, fn (Builder $owned) => $owned->whereNull('owner_revoked_at')))
                ->orWhere('linked_account_client_id', $account->getKey()))
            ->with('clinicalClient:id,organization_id,full_name');
    }
}
