<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;

class AiPromptPolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function viewAny(User $user): bool
    {
        $organization = $this->context->organization();

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiPrompts)
            || $this->authorizer->allows($user, $organization, OrganizationPermission::ViewAiRuns);
    }

    public function view(User $user, AiPrompt $prompt): bool
    {
        $organization = $this->context->organization();

        if ($prompt->organization_id !== $organization->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiPrompts)
            || $this->authorizer->allows($user, $organization, OrganizationPermission::ViewAiRuns);
    }

    public function create(User $user): bool
    {
        $organization = $this->context->organization();

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiPrompts);
    }

    public function update(User $user, AiPrompt $prompt): bool
    {
        $organization = $this->context->organization();

        if ($prompt->organization_id !== $organization->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiPrompts);
    }

    public function delete(User $user, AiPrompt $prompt): bool
    {
        $organization = $this->context->organization();

        if ($prompt->organization_id !== $organization->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ManageAiPrompts);
    }
}
