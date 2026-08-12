<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use LogicException;

class ContentSectionPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        try {
            return $this->authorizer->allows($user, $this->context->organization(), OrganizationPermission::ManageContent);
        } catch (LogicException) {
            return false;
        }
    }

    public function view(User $user, ContentSection $section): bool
    {
        return $this->authorizer->allows($user, $section->organization, OrganizationPermission::ManageContent);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ContentSection $section): bool
    {
        return $this->view($user, $section);
    }

    public function delete(User $user, ContentSection $section): bool
    {
        return $this->update($user, $section);
    }
}
