<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Services\Domain\Models\Service;

class ServicePolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
        private readonly OrganizationFeatureGate $features,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allowsCurrent($user);
    }

    public function view(User $user, Service $service): bool
    {
        return $this->features->isEnabled($service->organization, OrganizationFeature::ServiceCatalog)
            && $this->authorizer->allows($user, $service->organization, OrganizationPermission::ManageServices);
    }

    public function create(User $user): bool
    {
        return $this->allowsCurrent($user);
    }

    public function update(User $user, Service $service): bool
    {
        return $this->view($user, $service);
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->view($user, $service);
    }

    private function allowsCurrent(User $user): bool
    {
        $organization = $this->context->organization();

        return $this->features->isEnabled($organization, OrganizationFeature::ServiceCatalog)
            && $this->authorizer->allows($user, $organization, OrganizationPermission::ManageServices);
    }
}
