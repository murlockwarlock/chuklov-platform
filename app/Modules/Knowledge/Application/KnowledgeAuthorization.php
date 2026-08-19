<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;

final class KnowledgeAuthorization
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function authorizeView(User $actor, Organization $organization): void
    {
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewKnowledge);
    }

    public function authorizeManage(User $actor, Organization $organization): void
    {
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageKnowledge);
    }

    public function organizationForSource(User $actor, KnowledgeSource $source, OrganizationPermission $permission): Organization
    {
        $organization = $source->organization;

        if ($this->context->id() !== $organization->getKey()) {
            throw new AuthorizationException('The knowledge source is not available.');
        }

        $this->authorizer->authorize($actor, $organization, $permission);

        return $organization;
    }

    public function organizationForRevision(
        User $actor,
        KnowledgeSource $source,
        KnowledgeRevision $revision,
        OrganizationPermission $permission,
    ): Organization {
        $organization = $this->organizationForSource($actor, $source, $permission);

        if (
            (int) $revision->organization_id !== (int) $organization->getKey()
            || (int) $revision->knowledge_source_id !== (int) $source->getKey()
        ) {
            throw new AuthorizationException('The knowledge revision is not available.');
        }

        return $organization;
    }
}
