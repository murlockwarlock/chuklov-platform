<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

class GetBookingCancellationCutoff
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(): int
    {
        return (int) (OrganizationSetting::query()
            ->where('organization_id', $this->context->id())
            ->where('setting_key', OrganizationSettingKey::BookingCancellationCutoffMinutes->value)
            ->value('integer_value') ?? config('scheduling.booking_cancellation_cutoff_minutes'));
    }
}
