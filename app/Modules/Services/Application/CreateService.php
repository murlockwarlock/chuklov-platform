<?php

namespace App\Modules\Services\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Services\Domain\Models\Service;

class CreateService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, string $name, string $summary, bool $isActive): Service
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageServices);

        $service = new Service;
        $service->forceFill([
            'organization_id' => $organization->getKey(),
            'name' => $name,
            'summary' => $summary,
            'is_active' => $isActive,
        ]);
        $service->save();

        return $service;
    }
}
