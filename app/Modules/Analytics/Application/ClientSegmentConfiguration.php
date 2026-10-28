<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

final readonly class ClientSegmentConfiguration
{
    public function __construct(private OrganizationContext $context) {}

    public function value(OrganizationSettingKey $key, int $fallback): int
    {
        $setting = OrganizationSetting::query()
            ->where('organization_id', $this->context->id())
            ->where('setting_key', $key->value)
            ->first();

        return $setting?->integer_value ?? $fallback;
    }

    /** @return array<string, int> */
    public function values(): array
    {
        return [
            'new_days' => $this->value(OrganizationSettingKey::ClientSegmentNewDays, (int) config('crm.client_segments.new_days', 30)),
            'regular_completed_visits' => $this->value(OrganizationSettingKey::ClientSegmentRegularCompletedVisits, (int) config('crm.client_segments.regular_completed_visits', 3)),
            'dormant_days' => $this->value(OrganizationSettingKey::ClientSegmentDormantDays, (int) config('crm.client_segments.dormant_days', 90)),
            'sleeping_days' => $this->value(OrganizationSettingKey::ClientSegmentSleepingDays, (int) config('crm.client_segments.sleeping_days', 365)),
        ];
    }
}
