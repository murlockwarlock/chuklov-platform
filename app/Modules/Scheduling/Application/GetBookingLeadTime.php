<?php

namespace App\Modules\Scheduling\Application;

use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;

class GetBookingLeadTime
{
    /** @var array<int, int> */
    private static array $cached = [];

    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(): int
    {
        $organizationId = $this->context->id();

        if (array_key_exists($organizationId, self::$cached)) {
            return self::$cached[$organizationId];
        }

        return self::$cached[$organizationId] = (int) (OrganizationSetting::query()
            ->where('organization_id', $organizationId)
            ->where('setting_key', OrganizationSettingKey::BookingLeadTimeMinutes->value)
            ->value('integer_value') ?? 0);
    }

    public static function invalidate(?int $organizationId = null): void
    {
        if ($organizationId === null) {
            self::$cached = [];

            return;
        }

        unset(self::$cached[$organizationId]);
    }
}
