<?php

namespace App\Modules\B2B\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class VideoMeetingRequest
{
    public function __construct(
        public string $externalKey,
        public CarbonImmutable $startsAt,
        public int $durationMinutes,
        public string $timezone,
        public string $topic,
    ) {
        if ($this->externalKey === '' || $this->durationMinutes < 1 || $this->timezone === '' || $this->topic === '') {
            throw new InvalidArgumentException('The video meeting request is invalid.');
        }
    }

    public function endsAt(): CarbonImmutable
    {
        return $this->startsAt->addMinutes($this->durationMinutes);
    }
}
