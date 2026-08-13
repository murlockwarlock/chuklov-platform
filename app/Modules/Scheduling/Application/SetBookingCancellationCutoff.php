<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use InvalidArgumentException;

class SetBookingCancellationCutoff
{
    public function __construct(private readonly SetOrganizationSetting $settings) {}

    public function handle(User $actor, int $minutes): OrganizationSetting
    {
        if ($minutes < 0) {
            throw new InvalidArgumentException('The booking cancellation cutoff cannot be negative.');
        }

        return $this->settings->handle($actor, OrganizationSettingKey::BookingCancellationCutoffMinutes, $minutes);
    }
}
