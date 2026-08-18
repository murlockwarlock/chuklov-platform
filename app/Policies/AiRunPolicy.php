<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;

class AiRunPolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function viewAny(User $user): bool
    {
        $organization = $this->context->organization();

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ViewAiRuns);
    }

    public function view(User $user, AiRun $run): bool
    {
        $organization = $this->context->organization();

        if ($run->organization_id !== $organization->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ViewAiRuns);
    }

    public function viewTrace(User $user, AiRun $run): bool
    {
        $organization = $this->context->organization();

        if ($run->organization_id !== $organization->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ViewAiTrace);
    }

    public function review(User $user, AiRun $run): bool
    {
        $organization = $this->context->organization();

        if ($run->organization_id !== $organization->getKey()) {
            return false;
        }

        return $this->authorizer->allows($user, $organization, OrganizationPermission::ReviewAiProposals);
    }
}
