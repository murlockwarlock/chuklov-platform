<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

class GetBookingLeadTime
{
    private static ?int $cached = null;

    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(): int
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        return self::$cached = (int) (OrganizationSetting::query()
            ->where('organization_id', $this->context->id())
            ->where('setting_key', OrganizationSettingKey::BookingLeadTimeMinutes->value)
            ->value('integer_value') ?? 0);
    }

    public static function invalidate(): void
    {
        self::$cached = null;
    }
}
