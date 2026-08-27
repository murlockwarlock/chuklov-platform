<?php

namespace App\Modules\B2B\Domain\ValueObjects;

use Carbon\CarbonImmutable;

final readonly class VideoMeetingResult
{
    public function __construct(
        public VideoMeetingIdentity $identity,
        public string $joinUrl,
        public CarbonImmutable $synchronizedAt,
    ) {}
}
