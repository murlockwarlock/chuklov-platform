<?php

namespace App\Modules\B2B\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

final class GetB2bSalesCallDuration
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(): ?int
    {
        $value = OrganizationSetting::query()
            ->where('organization_id', $this->context->id())
            ->where('setting_key', OrganizationSettingKey::B2bSalesCallDurationMinutes->value)
            ->value('integer_value');

        if (! is_numeric($value)) {
            return null;
        }

        $minutes = (int) $value;

        return $minutes >= 1 && $minutes <= 1440 ? $minutes : null;
    }
}
