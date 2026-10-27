<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Scheduling\Domain\Enums\PatientNotificationRecipientPolicy;

final readonly class GetPatientNotificationRecipientPolicy
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(): PatientNotificationRecipientPolicy
    {
        $value = $this->context->organization()->settings()
            ->where('setting_key', OrganizationSettingKey::PatientNotificationRecipients->value)
            ->value('string_value');

        return PatientNotificationRecipientPolicy::tryFrom((string) $value)
            ?? PatientNotificationRecipientPolicy::Both;
    }
}
