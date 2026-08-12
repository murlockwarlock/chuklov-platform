<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Services\Domain\Models\Service;

class ServicePolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allowsCurrent($user);
    }

    public function view(User $user, Service $service): bool
    {
        return $this->authorizer->allows($user, $service->organization, OrganizationPermission::ManageServices);
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
        return $this->authorizer->allows($user, $this->context->organization(), OrganizationPermission::ManageServices);
    }
}
