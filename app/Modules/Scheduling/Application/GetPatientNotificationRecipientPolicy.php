<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Modules\Scheduling\Domain\Enums\PatientNotificationRecipientPolicy;

final readonly class GetPatientNotificationRecipientPolicy
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(?int $organizationId = null): PatientNotificationRecipientPolicy
    {
        $settings = $organizationId === null
            ? $this->context->organization()->settings()
            : OrganizationSetting::query()->where('organization_id', $organizationId);
        $value = $settings
            ->where('setting_key', OrganizationSettingKey::PatientNotificationRecipients->value)
            ->value('string_value');

        return PatientNotificationRecipientPolicy::tryFrom((string) $value)
            ?? PatientNotificationRecipientPolicy::Both;
    }
}
