<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use LogicException;

class OrganizationSettingPolicy
{
    public function __construct(
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        try {
            return $this->authorizer->allows($user, $this->context->organization(), OrganizationPermission::ManageSettings);
        } catch (LogicException) {
            return false;
        }
    }

    public function view(User $user, OrganizationSetting $setting): bool
    {
        return $this->authorizer->allows($user, $setting->organization, OrganizationPermission::ManageSettings);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, OrganizationSetting $setting): bool
    {
        return $this->view($user, $setting);
    }

    public function delete(User $user, OrganizationSetting $setting): bool
    {
        return $this->view($user, $setting);
    }
}
