<?php

namespace App\Modules\B2B\Domain\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class VideoMeetingResult
{
    public function __construct(
        public VideoMeetingIdentity $identity,
        public string $joinUrl,
        public CarbonImmutable $synchronizedAt,
        public ?CarbonImmutable $startsAt = null,
        public ?int $durationMinutes = null,
        public ?string $timezone = null,
        public ?string $agenda = null,
    ) {}

    public function matchesIdentity(VideoMeetingIdentity $expected): bool
    {
        if ($this->identity->meetingId !== $expected->meetingId) {
            return false;
        }

        if (! is_string($expected->meetingUuid) || trim($expected->meetingUuid) === '') {
            return true;
        }

        return is_string($this->identity->meetingUuid)
            && $this->identity->meetingUuid === $expected->meetingUuid;
    }

    public function matchesCorrelation(VideoMeetingRequest $expected): bool
    {
        return $expected->matchesCorrelation($this->agenda);
    }

    public function matchesIdentityAndCorrelation(
        VideoMeetingIdentity $expectedIdentity,
        VideoMeetingRequest $expectedRequest,
    ): bool {
        return $this->matchesIdentity($expectedIdentity)
            && $this->matchesCorrelation($expectedRequest);
    }

    public function matchesRequest(VideoMeetingRequest $expected): bool
    {
        return $this->matchesCorrelation($expected)
            && $this->startsAt instanceof CarbonImmutable
            && $this->startsAt->equalTo($expected->startsAt->utc())
            && $this->durationMinutes === $expected->durationMinutes
            && $this->timezone === $expected->timezone;
    }
}
