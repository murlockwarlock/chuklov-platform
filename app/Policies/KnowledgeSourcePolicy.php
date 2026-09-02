<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;

final class KnowledgeSourcePolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->authorizer->allows($user, $this->context->organization(), OrganizationPermission::ViewKnowledge);
    }

    public function view(User $user, KnowledgeSource $source): bool
    {
        return $source->organization_id === $this->context->id()
            && $this->authorizer->allows($user, $source->organization, OrganizationPermission::ViewKnowledge);
    }

    public function create(User $user): bool
    {
        return $this->authorizer->allows($user, $this->context->organization(), OrganizationPermission::ManageKnowledge);
    }

    public function update(User $user, KnowledgeSource $source): bool
    {
        return $source->organization_id === $this->context->id()
            && $this->authorizer->allows($user, $source->organization, OrganizationPermission::ManageKnowledge);
    }

    public function delete(User $user, KnowledgeSource $source): bool
    {
        return $source->organization_id === $this->context->id()
            && $this->authorizer->allows($user, $source->organization, OrganizationPermission::ManageKnowledge);
    }
}
