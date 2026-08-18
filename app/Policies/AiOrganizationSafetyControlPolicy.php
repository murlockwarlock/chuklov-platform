<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;

class AiOrganizationSafetyControlPolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function viewAny(User $user): bool
    {
        $organization = $this->context->organization();

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiProviders);
    }

    public function view(User $user, AiOrganizationSafetyControl $control): bool
    {
        $organization = $this->context->organization();

        if ($control->organization_id !== $organization->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiProviders);
    }

    public function update(User $user, AiOrganizationSafetyControl $control): bool
    {
        $organization = $this->context->organization();

        if ($control->organization_id !== $organization->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiProviders);
    }
}
