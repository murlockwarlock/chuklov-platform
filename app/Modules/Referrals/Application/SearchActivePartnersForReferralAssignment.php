<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Referrals\Domain\Enums\ReferralPartnerStatus;

final readonly class SearchActivePartnersForReferralAssignment
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private ClientSearch $clients,
    ) {}

    /** @return array<int|string, string> */
    public function handle(User $actor, string $search, int $excludedClientId): array
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        $base = $this->clients->withVerifiedTelegramIdentity($this->clients->query($actor, $search))
            ->where('id', '<>', $excludedClientId)
            ->with('referralPartnerProfile');
        $active = (clone $base)
            ->whereHas('referralPartnerProfile', fn ($query) => $query->where('status', ReferralPartnerStatus::Active->value))
            ->orderBy('full_name')
            ->limit(ClientSearch::MAX_RESULTS)
            ->get(['id', 'full_name', 'email', 'phone']);
        $activeIds = $active->modelKeys();
        $remaining = max(ClientSearch::MAX_RESULTS - count($activeIds), 0);
        $others = $remaining === 0
            ? collect()
            : $base
                ->whereNotIn('id', $activeIds)
                ->orderBy('full_name')
                ->limit($remaining)
                ->get(['id', 'full_name', 'email', 'phone']);

        return $this->clients->formatOptionLabels($active->concat($others));
    }

    public function optionLabel(User $actor, mixed $value): ?string
    {
        if (! is_scalar($value) || ! is_numeric($value)) {
            return null;
        }

        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);
        $client = $this->clients->withVerifiedTelegramIdentity(
            Client::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey((int) $value),
        )->first();

        return $client instanceof Client ? $this->clients->formatOptionLabel($client) : null;
    }
}
