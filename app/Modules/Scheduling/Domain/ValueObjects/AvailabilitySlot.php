<?php

namespace App\Modules\Scheduling\Domain\ValueObjects;

use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use Carbon\CarbonImmutable;

final readonly class AvailabilitySlot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public CarbonImmutable $blockingEndsAt,
        public string $scheduleTimezone,
        public string $displayTimezone,
        public VisitFormat $format,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'startsAt' => $this->startsAt->utc()->toIso8601String(),
            'endsAt' => $this->endsAt->utc()->toIso8601String(),
            'blockingEndsAt' => $this->blockingEndsAt->utc()->toIso8601String(),
            'displayStartsAt' => $this->startsAt->setTimezone($this->displayTimezone)->toIso8601String(),
            'displayEndsAt' => $this->endsAt->setTimezone($this->displayTimezone)->toIso8601String(),
            'scheduleTimezone' => $this->scheduleTimezone,
            'displayTimezone' => $this->displayTimezone,
            'format' => $this->format->value,
        ];
    }
}
