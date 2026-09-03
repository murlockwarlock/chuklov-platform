<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;

final readonly class GetHomeVisitOccupiedBuffer
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(): int
    {
        $setting = $this->context->organization()->settings()
            ->where('setting_key', OrganizationSettingKey::HomeVisitOccupiedBufferMinutes->value)
            ->first();

        if ($setting?->integer_value !== null) {
            return max(0, (int) $setting->integer_value);
        }

        return (int) config('scheduling.home_visit_occupied_buffer_minutes', 0);
    }
}
