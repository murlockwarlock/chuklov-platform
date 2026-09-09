<?php

namespace App\Modules\Tracker\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;

final class GetTrackerSettings
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function enabled(): bool
    {
        return $this->value(OrganizationSettingKey::TrackerEnabled, true);
    }

    public function freeMode(): bool
    {
        return $this->value(OrganizationSettingKey::TrackerFreeMode, false);
    }

    private function value(OrganizationSettingKey $key, bool $default): bool
    {
        $setting = $this->context->organization()->settings()
            ->where('setting_key', $key->value)
            ->first();

        return $setting === null ? $default : (bool) $setting->typedValue();
    }
}
