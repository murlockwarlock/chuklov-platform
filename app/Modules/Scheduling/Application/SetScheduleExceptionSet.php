<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\ValueObjects\LocalDate;
use App\Modules\Scheduling\Domain\ValueObjects\ScheduleExceptionDefinition;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SetScheduleExceptionSet
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly ScheduleMutationImpactCalculator $impactCalculator,
        private readonly EnsureScheduleMutationImpactAcknowledged $impactAcknowledgement,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, list<array<string, mixed>>> $definitionsByDate */
    public function handle(
        User $actor,
        Specialist $specialist,
        array $definitionsByDate,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
    ): void {
        $organization = $this->context->organization();

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $definitions = $this->normalizeDefinitions($definitionsByDate);

        if ($definitions === []) {
            return;
        }

        DB::transaction(function () use ($actor, $organization, $specialist, $definitions, $acknowledgeImpact, $acknowledgedImpactDigest): void {
            $lockedSpecialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($specialist->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $existing = ScheduleException::query()
                ->where('organization_id', $organization->getKey())
                ->where('specialist_id', $lockedSpecialist->getKey())
                ->whereIn('exception_date', array_keys($definitions))
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy(fn (ScheduleException $exception): string => $exception->dateKey());
            $changedDefinitions = [];
            $impactDefinitions = [];

            foreach ($definitions as $date => $desired) {
                $current = $existing->get($date, new Collection);
                if ($this->matches($current, $desired)) {
                    continue;
                }

                $changedDefinitions[$date] = $desired;
                $impactDefinitions[$date] = $desired;
            }

            if ($changedDefinitions === []) {
                return;
            }

            $impact = $this->impactCalculator->forExceptionSet($lockedSpecialist, $impactDefinitions);
            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);

            foreach ($changedDefinitions as $date => $desired) {
                foreach ($existing->get($date, new Collection) as $exception) {
                    $exception->delete();
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'specialist.schedule.exception.deleted',
                        targetType: ScheduleException::class,
                        targetId: (string) $exception->getKey(),
                        metadata: ['source' => 'crm'],
                    );
                }

                foreach ($desired as $definition) {
                    $exception = new ScheduleException;
                    $exception->forceFill([
                        'organization_id' => $organization->getKey(),
                        'specialist_id' => $lockedSpecialist->getKey(),
                        'exception_date' => $definition->date,
                        'exception_type' => $definition->type,
                        'start_time' => $definition->interval?->start,
                        'end_time' => $definition->interval?->end,
                        'reason' => $definition->reason,
                        'is_active' => true,
                    ])->save();
                    $this->audit->handle(
                        organization: $organization,
                        actor: $actor,
                        action: 'specialist.schedule.exception.created',
                        targetType: ScheduleException::class,
                        targetId: (string) $exception->getKey(),
                        metadata: [
                            'exception_type' => $definition->type->value,
                            'source' => 'crm',
                        ],
                    );
                }
            }

            if ($impact->hasConflicts()) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'schedule.mutation.acknowledged',
                    targetType: Specialist::class,
                    targetId: (string) $lockedSpecialist->getKey(),
                    metadata: [
                        'source' => 'crm',
                        'mutation' => 'schedule_exception_set',
                        'affected_booking_count' => $impact->count(),
                        'impact_digest' => $impact->digest,
                    ],
                );
            }
        });
    }

    /** @param array<string, list<array<string, mixed>>> $definitionsByDate
     * @return array<string, list<ScheduleExceptionDefinition>>
     */
    private function normalizeDefinitions(array $definitionsByDate): array
    {
        $normalized = [];

        foreach ($definitionsByDate as $date => $definitions) {
            $date = LocalDate::from((string) $date)->value;
            if (! is_array($definitions)) {
                throw new InvalidArgumentException('The schedule exception set is invalid.');
            }

            $dateDefinitions = [];
            foreach ($definitions as $definition) {
                if (! is_array($definition)) {
                    throw new InvalidArgumentException('The schedule exception set is invalid.');
                }

                $dateDefinitions[] = ScheduleExceptionDefinition::from([
                    ...$definition,
                    'exception_date' => $date,
                ]);
            }

            $this->ensureDateDefinitionsDoNotOverlap($dateDefinitions);
            usort($dateDefinitions, static fn (
                ScheduleExceptionDefinition $left,
                ScheduleExceptionDefinition $right,
            ): int => [
                $left->type === ScheduleExceptionType::DayOff ? -1 : 0,
                $left->interval?->startMinutes() ?? 0,
            ] <=> [
                $right->type === ScheduleExceptionType::DayOff ? -1 : 0,
                $right->interval?->startMinutes() ?? 0,
            ]);
            $normalized[$date] = $dateDefinitions;
        }

        ksort($normalized);

        return $normalized;
    }

    /** @param list<ScheduleExceptionDefinition> $definitions */
    private function ensureDateDefinitionsDoNotOverlap(array $definitions): void
    {
        $hasDayOff = collect($definitions)->contains(
            static fn (ScheduleExceptionDefinition $definition): bool => $definition->type === ScheduleExceptionType::DayOff,
        );

        if ($hasDayOff && count($definitions) !== 1) {
            throw ValidationException::withMessages([
                'exception_date' => ['Для одной даты выберите выходной или рабочие интервалы.'],
            ]);
        }

        foreach ($definitions as $index => $definition) {
            foreach (array_slice($definitions, 0, $index) as $previous) {
                if ($definition->interval === null || $previous->interval === null) {
                    continue;
                }

                if ($definition->interval->startMinutes() < $previous->interval->endMinutes()
                    && $definition->interval->endMinutes() > $previous->interval->startMinutes()) {
                    throw ValidationException::withMessages([
                        'exception_date' => ['Рабочие интервалы не должны пересекаться.'],
                    ]);
                }
            }
        }
    }

    /** @param Collection<int, ScheduleException> $existing
     * @param  list<ScheduleExceptionDefinition>  $desired
     */
    private function matches(Collection $existing, array $desired): bool
    {
        $current = $existing->map(static fn (ScheduleException $exception): string => implode('|', [
            $exception->exception_type->value,
            $exception->start_time === null ? '' : substr((string) $exception->start_time, 0, 5),
            $exception->end_time === null ? '' : substr((string) $exception->end_time, 0, 5),
            (string) ($exception->reason ?? ''),
        ]))->sort()->values()->all();
        $target = collect($desired)->map(static fn (ScheduleExceptionDefinition $definition): string => implode('|', [
            $definition->type->value,
            $definition->interval?->start ?? '',
            $definition->interval?->end ?? '',
            $definition->reason ?? '',
        ]))->sort()->values()->all();

        return $current === $target;
    }
}
