<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\ValueObjects\IanaTimezone;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\ValueObjects\ScheduleExceptionDefinition;
use App\Modules\Scheduling\Domain\ValueObjects\WallClockInterval;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ApplyMonthlySchedulePreset
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly CreateScheduleException $createException,
        private readonly DeleteScheduleException $deleteException,
        private readonly ScheduleMutationImpactCalculator $impactCalculator,
        private readonly EnsureScheduleMutationImpactAcknowledged $impactAcknowledgement,
    ) {}

    public function handle(
        User $actor,
        Specialist $specialist,
        string $month,
        string $preset,
        string $startTime,
        string $endTime,
        bool $breakEnabled,
        string $breakStart,
        string $breakEnd,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
    ): void {
        $organization = $this->context->organization();
        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new InvalidArgumentException('The specialist is outside the current organization.');
        }
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);

        $definitions = $this->definitionsForMonth(
            specialist: $specialist,
            month: $month,
            preset: $preset,
            startTime: $startTime,
            endTime: $endTime,
            breakEnabled: $breakEnabled,
            breakStart: $breakStart,
            breakEnd: $breakEnd,
        );

        DB::transaction(function () use ($actor, $specialist, $definitions, $acknowledgeImpact, $acknowledgedImpactDigest): void {
            $existing = ScheduleException::query()
                ->where('organization_id', $this->context->id())
                ->where('specialist_id', $specialist->getKey())
                ->whereIn('exception_date', array_keys($definitions))
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy(fn (ScheduleException $exception): string => $exception->dateKey());
            $changedDefinitions = [];
            $impactDefinitions = [];

            foreach ($definitions as $date => $desired) {
                $dateExceptions = $existing->get($date, collect());
                if ($this->matches($dateExceptions, $desired)) {
                    continue;
                }

                $changedDefinitions[$date] = $desired;
                $impactDefinitions[$date] = array_map(
                    static fn (array $definition): ScheduleExceptionDefinition => ScheduleExceptionDefinition::from($definition),
                    $desired,
                );
            }

            $impact = $this->impactCalculator->forExceptionSet($specialist, $impactDefinitions);
            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);

            foreach ($changedDefinitions as $date => $desired) {
                $dateExceptions = $existing->get($date, collect());

                foreach ($dateExceptions as $exception) {
                    $deleteImpact = $this->impactCalculator->forExceptionDeletion($specialist, $exception);
                    $this->deleteException->handle(
                        actor: $actor,
                        exception: $exception,
                        acknowledgeImpact: $deleteImpact->hasConflicts(),
                        acknowledgedImpactDigest: $deleteImpact->hasConflicts() ? $deleteImpact->digest : null,
                    );
                }

                foreach ($desired as $definition) {
                    $exceptionDefinition = ScheduleExceptionDefinition::from($definition);
                    $createImpact = $this->impactCalculator->forException($specialist, $exceptionDefinition);
                    $this->createException->handle(
                        actor: $actor,
                        specialist: $specialist,
                        attributes: $definition,
                        acknowledgeImpact: $createImpact->hasConflicts(),
                        acknowledgedImpactDigest: $createImpact->hasConflicts() ? $createImpact->digest : null,
                    );
                }
            }
        });
    }

    /** @return array<string, list<array{exception_date: string, exception_type: string, start_time: string|null, end_time: string|null, reason: string|null}>> */
    private function definitionsForMonth(
        Specialist $specialist,
        string $month,
        string $preset,
        string $startTime,
        string $endTime,
        bool $breakEnabled,
        string $breakStart,
        string $breakEnd,
    ): array {
        $displayTimezone = IanaTimezone::from($this->context->defaultTimezone())->value;
        $scheduleTimezone = IanaTimezone::from($specialist->timezone ?? $displayTimezone)->value;
        $monthDate = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', new DateTimeZone($displayTimezone));
        if (! $monthDate instanceof CarbonImmutable || $monthDate->format('Y-m') !== $month) {
            throw new InvalidArgumentException('The schedule month is invalid.');
        }

        $intervals = $this->intervals($startTime, $endTime, $breakEnabled, $breakStart, $breakEnd);
        $definitions = [];
        for ($day = 1; $day <= $monthDate->daysInMonth; $day++) {
            $displayDate = $monthDate->setDay($day);
            $scheduleDate = $displayDate
                ->setTime(12, 0)
                ->setTimezone($scheduleTimezone)
                ->toDateString();
            $isWorking = match ($preset) {
                'weekdays' => $displayDate->dayOfWeekIso <= 5,
                'all' => true,
                'even' => $displayDate->day % 2 === 0,
                'odd' => $displayDate->day % 2 === 1,
                'clear' => false,
                default => throw new InvalidArgumentException('The schedule preset is invalid.'),
            };

            $definitions[$scheduleDate] = $isWorking
                ? array_map(
                    static fn (array $interval): array => [
                        'exception_date' => $scheduleDate,
                        'exception_type' => ScheduleExceptionType::CustomWindow->value,
                        'start_time' => $interval['start_time'],
                        'end_time' => $interval['end_time'],
                        'reason' => null,
                    ],
                    $intervals,
                )
                : [[
                    'exception_date' => $scheduleDate,
                    'exception_type' => ScheduleExceptionType::DayOff->value,
                    'start_time' => null,
                    'end_time' => null,
                    'reason' => null,
                ]];
        }

        return $definitions;
    }

    /** @return list<array{start_time: string, end_time: string}> */
    private function intervals(
        string $startTime,
        string $endTime,
        bool $breakEnabled,
        string $breakStart,
        string $breakEnd,
    ): array {
        if (! $breakEnabled) {
            $interval = WallClockInterval::from($startTime, $endTime);

            return [['start_time' => $interval->start, 'end_time' => $interval->end]];
        }

        $first = WallClockInterval::from($startTime, $breakStart);
        $second = WallClockInterval::from($breakEnd, $endTime);

        return [
            ['start_time' => $first->start, 'end_time' => $first->end],
            ['start_time' => $second->start, 'end_time' => $second->end],
        ];
    }

    /**
     * @param  Collection<int, ScheduleException>  $existing
     * @param  list<array{exception_date: string, exception_type: string, start_time: string|null, end_time: string|null, reason: string|null}>  $desired
     */
    private function matches(Collection $existing, array $desired): bool
    {
        if ($existing->count() !== count($desired)) {
            return false;
        }

        $current = $existing
            ->map(static fn (ScheduleException $exception): array => [
                'exception_type' => $exception->exception_type->value,
                'start_time' => $exception->start_time === null ? null : substr((string) $exception->start_time, 0, 5),
                'end_time' => $exception->end_time === null ? null : substr((string) $exception->end_time, 0, 5),
            ])
            ->sortBy(fn (array $definition): array => [$definition['exception_type'], $definition['start_time'] ?? ''])
            ->values()
            ->all();
        $target = collect($desired)
            ->map(static fn (array $definition): array => [
                'exception_type' => $definition['exception_type'],
                'start_time' => $definition['start_time'] === null ? null : substr((string) $definition['start_time'], 0, 5),
                'end_time' => $definition['end_time'] === null ? null : substr((string) $definition['end_time'], 0, 5),
            ])
            ->sortBy(fn (array $definition): array => [$definition['exception_type'], $definition['start_time'] ?? ''])
            ->values()
            ->all();

        return $current === $target;
    }
}
