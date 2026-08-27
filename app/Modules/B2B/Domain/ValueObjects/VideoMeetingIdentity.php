<?php

namespace App\Modules\B2B\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class VideoMeetingIdentity
{
    public function __construct(
        public string $meetingId,
        public ?string $meetingUuid = null,
    ) {
        if (trim($this->meetingId) === '') {
            throw new InvalidArgumentException('The video meeting identity is invalid.');
        }
    }
}
