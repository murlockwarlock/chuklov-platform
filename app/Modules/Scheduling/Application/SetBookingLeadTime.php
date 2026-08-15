<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingType;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SetBookingLeadTime
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
        private readonly GetBookingLeadTime $leadTime,
    ) {}

    public function handle(User $actor, int $minutes): OrganizationSetting
    {
        if ($minutes < 0) {
            throw new InvalidArgumentException('The booking lead time cannot be negative.');
        }

        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);

        $result = DB::transaction(function () use ($actor, $minutes, $organization): OrganizationSetting {
            $setting = OrganizationSetting::query()
                ->where('organization_id', $organization->getKey())
                ->where('setting_key', OrganizationSettingKey::BookingLeadTimeMinutes->value)
                ->lockForUpdate()
                ->first() ?? new OrganizationSetting;
            $setting->forceFill([
                'organization_id' => $organization->getKey(),
                'setting_key' => OrganizationSettingKey::BookingLeadTimeMinutes->value,
                'value_type' => OrganizationSettingType::Integer,
                'string_value' => null,
                'integer_value' => $minutes,
                'boolean_value' => null,
            ]);
            $setting->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'organization.scheduling.lead_time.updated',
                targetType: OrganizationSetting::class,
                targetId: (string) $setting->getKey(),
                metadata: ['minutes' => $minutes],
            );

            return $setting->refresh();
        });

        $this->leadTime->invalidate();

        return $result;
    }
}
