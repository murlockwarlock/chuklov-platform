<?php

namespace App\Modules\Scheduling\Domain\ValueObjects;

use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use InvalidArgumentException;

final readonly class LocationDayDefinition
{
    private function __construct(
        public string $areaName,
        public ?int $weekday,
        public ?string $specificDate,
        public string $startTime,
        public string $endTime,
        public string $timezone,
        public bool $isActive,
        public ?string $notes,
    ) {}

    public static function from(
        string $areaName,
        ?int $weekday,
        ?string $specificDate,
        string $startTime,
        string $endTime,
        string $timezone,
        bool $isActive,
        ?string $notes,
    ): self {
        $areaName = trim($areaName);
        $specificDate = $specificDate === null ? null : trim($specificDate);
        $notes = $notes === null ? null : trim($notes);

        if ($areaName === '' || mb_strlen($areaName) > 160) {
            throw new InvalidArgumentException('The location-day area is invalid.');
        }

        if ($weekday === null && ($specificDate === null || $specificDate === '')) {
            throw new InvalidArgumentException('A weekday or specific date is required.');
        }

        if ($weekday !== null && ($weekday < 1 || $weekday > 7)) {
            throw new InvalidArgumentException('The location-day weekday is invalid.');
        }

        if ($specificDate !== null && $specificDate !== '') {
            LocalDate::from($specificDate);
        } else {
            $specificDate = null;
        }

        $interval = WallClockInterval::from($startTime, $endTime);
        $timezone = IanaTimezone::from($timezone)->value;

        if ($notes !== null && mb_strlen($notes) > 500) {
            throw new InvalidArgumentException('The location-day notes are invalid.');
        }

        return new self($areaName, $weekday, $specificDate, $interval->start, $interval->end, $timezone, $isActive, $notes);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'area_name' => $this->areaName,
            'weekday' => $this->weekday,
            'specific_date' => $this->specificDate,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'timezone' => $this->timezone,
            'is_active' => $this->isActive,
            'notes' => $this->notes === '' ? null : $this->notes,
        ];
    }
}
