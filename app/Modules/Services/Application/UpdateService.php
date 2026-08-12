<?php

namespace App\Modules\Services\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Services\Domain\Models\Service;

class UpdateService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, Service $service, string $name, string $summary, bool $isActive): Service
    {
        abort_unless($service->organization_id === $this->context->id(), 404);
        $this->authorizer->authorize($actor, $service->organization, OrganizationPermission::ManageServices);

        $service->update([
            'name' => $name,
            'summary' => $summary,
            'is_active' => $isActive,
        ]);

        return $service->refresh();
    }
}
