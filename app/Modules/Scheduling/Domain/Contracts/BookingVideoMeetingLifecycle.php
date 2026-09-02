<?php

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\MeetingLinkMode;
use App\Modules\Scheduling\Domain\Models\Booking;

interface BookingVideoMeetingLifecycle
{
    public function resolveMeetingLinkMode(Organization $organization, ?MeetingLinkMode $requested): MeetingLinkMode;

    public function scheduleCreate(Organization $organization, Booking $booking): void;

    public function scheduleReschedule(Organization $organization, Booking $booking): void;

    public function scheduleCancel(Organization $organization, Booking $booking): void;
}
