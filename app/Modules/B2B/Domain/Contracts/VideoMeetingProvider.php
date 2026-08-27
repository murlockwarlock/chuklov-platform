<?php

namespace App\Modules\B2B\Domain\Contracts;

use App\Modules\B2B\Domain\ValueObjects\VideoMeetingIdentity;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingRequest;
use App\Modules\B2B\Domain\ValueObjects\VideoMeetingResult;
use App\Modules\Organizations\Domain\Models\Organization;

interface VideoMeetingProvider
{
    public function name(): string;

    public function createMeeting(Organization $organization, VideoMeetingRequest $request): VideoMeetingResult;

    public function updateMeeting(
        Organization $organization,
        VideoMeetingIdentity $identity,
        VideoMeetingRequest $request,
    ): void;

    public function cancelMeeting(Organization $organization, VideoMeetingIdentity $identity): void;

    public function obtainHostLaunchUrl(Organization $organization, VideoMeetingIdentity $identity): string;

    public function findMeeting(Organization $organization, VideoMeetingRequest $request): ?VideoMeetingResult;
}
