<?php

namespace App\Modules\Organizations\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

final class ClearOrganizationSetting
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, OrganizationSettingKey $key): void
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize(
            $actor,
            $organization,
            in_array($key, [
                OrganizationSettingKey::B2bSalesCallDurationMinutes,
                OrganizationSettingKey::B2bZoomHostLicensed,
            ], true)
                ? OrganizationPermission::ManageScheduling
                : OrganizationPermission::ManageSettings,
        );

        DB::transaction(function () use ($actor, $key, $organization): void {
            $setting = OrganizationSetting::query()
                ->where('organization_id', $organization->getKey())
                ->where('setting_key', $key->value)
                ->lockForUpdate()
                ->first();

            if (! $setting instanceof OrganizationSetting) {
                return;
            }

            $targetId = (string) $setting->getKey();
            $valueType = $setting->value_type->value;
            $setting->delete();

            if ($key === OrganizationSettingKey::DefaultTimezone) {
                $this->context->invalidateDefaultTimezone();
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'organization.setting.removed',
                targetType: OrganizationSetting::class,
                targetId: $targetId,
                metadata: ['setting_key' => $key->value, 'value_type' => $valueType],
            );
        });
    }
}
