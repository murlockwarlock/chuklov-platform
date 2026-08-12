<?php

namespace App\Modules\Organizations\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

class GetOrganizationSetting
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, OrganizationSettingKey $key): ?OrganizationSetting
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);

        return $organization->settings()->where('setting_key', $key->value)->first();
    }
}
