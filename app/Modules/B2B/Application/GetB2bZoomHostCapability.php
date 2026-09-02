<?php

namespace App\Modules\B2B\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingType;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

final class GetB2bZoomHostCapability
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function isLicensed(): bool
    {
        $setting = OrganizationSetting::query()
            ->where('organization_id', $this->context->id())
            ->where('setting_key', OrganizationSettingKey::B2bZoomHostLicensed->value)
            ->first();

        return $setting instanceof OrganizationSetting
            && $setting->value_type === OrganizationSettingType::Boolean
            && $setting->boolean_value === true;
    }

    public function maxAutomaticDurationMinutes(): int
    {
        return $this->isLicensed()
            ? (int) config('b2b.zoom.licensed_max_duration_minutes', 1440)
            : (int) config('b2b.zoom.basic_max_duration_minutes', 40);
    }

    public function supportsAutomaticDuration(int $durationMinutes): bool
    {
        return $durationMinutes >= 1 && $durationMinutes <= $this->maxAutomaticDurationMinutes();
    }

    public function configurationError(): string
    {
        return 'Automatic Zoom sales-call duration exceeds the current host capability of '
            .$this->maxAutomaticDurationMinutes().' minutes. Enable a licensed Zoom host or use a shorter business duration.';
    }
}
