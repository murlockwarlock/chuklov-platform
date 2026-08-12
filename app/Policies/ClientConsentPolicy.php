<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use LogicException;

class ClientConsentPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
        private readonly OrganizationFeatureGate $features,
    ) {}

    public function viewAny(User $user): bool
    {
        try {
            return $this->allows($user, $this->context->organization(), OrganizationPermission::ViewClients);
        } catch (LogicException) {
            return false;
        }
    }

    public function view(User $user, ClientConsent $consent): bool
    {
        return $this->allows($user, $consent->client->organization, OrganizationPermission::ViewClients);
    }

    public function create(User $user): bool
    {
        try {
            return $this->allows($user, $this->context->organization(), OrganizationPermission::RecordConsent);
        } catch (LogicException) {
            return false;
        }
    }

    private function allows(User $user, Organization $organization, OrganizationPermission $permission): bool
    {
        return $this->features->isEnabled($organization, OrganizationFeature::ClientRecords)
            && $this->authorizer->allows($user, $organization, $permission);
    }
}
