<?php

namespace App\Modules\Tracker\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use Carbon\CarbonImmutable;
use DateTimeZone;

final readonly class TrackerTaskSchedule
{
    public function __construct(private OrganizationContext $context) {}

    public function timezone(Client $client): DateTimeZone
    {
        $timezone = trim((string) $client->timezone) !== ''
            ? (string) $client->timezone
            : $this->context->defaultTimezone();

        try {
            return new DateTimeZone($timezone);
        } catch (\Exception) {
            return new DateTimeZone($this->context->defaultTimezone());
        }
    }

    public function localDate(Client $client, ?CarbonImmutable $at = null): CarbonImmutable
    {
        return ($at ?? CarbonImmutable::now('UTC'))->setTimezone($this->timezone($client))->startOfDay();
    }

    public function isDue(TrackerTask $task, CarbonImmutable $localDate): bool
    {
        $date = $localDate->startOfDay();
        $startsOn = $task->starts_on->startOfDay();
        $endsOn = $task->ends_on?->startOfDay();

        if ($date->lessThan($startsOn) || ($endsOn !== null && $date->greaterThan($endsOn))) {
            return false;
        }

        return match ($task->frequency) {
            TrackerTaskFrequency::Daily => true,
            TrackerTaskFrequency::Weekly => $date->dayOfWeekIso === $task->week_day,
        };
    }

    public function periodStart(TrackerTask $task, CarbonImmutable $localDate): CarbonImmutable
    {
        return $task->frequency === TrackerTaskFrequency::Weekly
            ? $localDate->startOfWeek(CarbonImmutable::MONDAY)
            : $localDate->startOfDay();
    }

    public function firstOccurrenceAt(TrackerTask $task, Client $client, ?CarbonImmutable $at = null): CarbonImmutable
    {
        $date = $this->localDate($client, $at);

        for ($offset = 0; $offset <= 370; $offset++) {
            $candidate = $date->addDays($offset);
            if ($this->isDue($task, $candidate)) {
                return $candidate->setTimezone('UTC');
            }
        }

        return $date->setTimezone('UTC');
    }
}
