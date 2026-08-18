<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;

class AiProviderConfigurationPolicy
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

    public function view(User $user, AiProviderConfiguration $config): bool
    {
        $organization = $this->context->organization();

        if ($config->organization_id !== $organization->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiProviders);
    }

    public function create(User $user): bool
    {
        $organization = $this->context->organization();

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiProviders);
    }

    public function update(User $user, AiProviderConfiguration $config): bool
    {
        $organization = $this->context->organization();

        if ($config->organization_id !== $organization->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiProviders);
    }

    public function delete(User $user, AiProviderConfiguration $config): bool
    {
        $organization = $this->context->organization();

        if ($config->organization_id !== $organization->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiProviders);
    }
}
