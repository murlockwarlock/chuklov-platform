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

        if ($setting === null || $setting->integer_value === null) {
            return $fallback;
        }

        return $setting->integer_value;
    }

    public function stringValue(OrganizationSettingKey $key, ?string $fallback = null): ?string
    {
        $setting = OrganizationSetting::query()
            ->where('organization_id', $this->context->id())
            ->where('setting_key', $key->value)
            ->first();

        return $setting instanceof OrganizationSetting ? ($setting->string_value ?? $fallback) : $fallback;
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        $serviceIds = $this->serviceIds(OrganizationSettingKey::ClientSegmentWarmLeadServiceIds);
        $targetServiceIds = $this->serviceIds(OrganizationSettingKey::ClientSegmentWarmLeadTargetServiceIds);

        return [
            'new_days' => $this->value(OrganizationSettingKey::ClientSegmentNewDays, (int) config('crm.client_segments.new_days', 30)),
            'regular_completed_visits' => $this->value(OrganizationSettingKey::ClientSegmentRegularCompletedVisits, (int) config('crm.client_segments.regular_completed_visits', 3)),
            'dormant_days' => $this->value(OrganizationSettingKey::ClientSegmentDormantDays, (int) config('crm.client_segments.dormant_days', 90)),
            'sleeping_days' => $this->value(OrganizationSettingKey::ClientSegmentSleepingDays, (int) config('crm.client_segments.sleeping_days', 365)),
            'warm_lead_service_ids' => $serviceIds,
            'warm_lead_target_service_ids' => $targetServiceIds,
            'penalty_tag' => trim((string) $this->stringValue(OrganizationSettingKey::ClientSegmentPenaltyTag, '')),
        ];
    }

    /** @return list<int> */
    private function serviceIds(OrganizationSettingKey $key): array
    {
        $decoded = json_decode((string) $this->stringValue($key, '[]'), true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $decoded),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
