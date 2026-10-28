<?php

namespace App\Modules\ClientPortal\Application;

use App\Modules\ClientPortal\Domain\Enums\RoadMapAvailabilityMode;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;

final readonly class GetRoadMapAvailabilityMode
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(): RoadMapAvailabilityMode
    {
        $value = $this->context->organization()->settings()
            ->where('setting_key', OrganizationSettingKey::RoadMapAvailabilityMode->value)
            ->value('string_value');

        return RoadMapAvailabilityMode::tryFrom((string) $value)
            ?? RoadMapAvailabilityMode::ReviewedCourseReport;
    }
}
