<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\ValueObjects\ScheduleExceptionDefinition;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateScheduleException
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly ScheduleMutationImpactCalculator $impactCalculator,
        private readonly EnsureScheduleMutationImpactAcknowledged $impactAcknowledgement,
        private readonly RecordAuditEvent $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(
        User $actor,
        ScheduleException $exception,
        array $attributes,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
        ?Specialist $targetSpecialist = null,
    ): ScheduleException {
        $organization = $this->context->organization();

        if ((int) $exception->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The schedule exception is outside the current organization.');
        }

        if ($targetSpecialist !== null && (int) $targetSpecialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $definition = ScheduleExceptionDefinition::from($attributes);

        return DB::transaction(function () use ($actor, $organization, $exception, $definition, $acknowledgeImpact, $acknowledgedImpactDigest, $targetSpecialist): ScheduleException {
            $locked = ScheduleException::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($exception->getKey())
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();

            $specialistIds = array_values(array_unique([
                (int) $locked->specialist_id,
                (int) ($targetSpecialist?->getKey() ?? $locked->specialist_id),
            ]));
            sort($specialistIds);
            $specialists = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereIn('id', $specialistIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $currentSpecialist = $specialists->get((int) $locked->specialist_id);
            $newSpecialist = $specialists->get((int) ($targetSpecialist?->getKey() ?? $locked->specialist_id));
            if (! $currentSpecialist instanceof Specialist || ! $newSpecialist instanceof Specialist) {
                throw new AuthorizationException('The schedule exception specialist is outside the current organization.');
            }

            $impact = $this->impactCalculator->forExceptionUpdate(
                currentSpecialist: $currentSpecialist,
                exception: $locked,
                targetSpecialist: $newSpecialist,
                definition: $definition,
            );

            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);
            $this->ensureNoOverlap($organization->getKey(), $newSpecialist->getKey(), $locked->getKey(), $definition);

            $locked->forceFill([
                'specialist_id' => $newSpecialist->getKey(),
                'exception_date' => $definition->date,
                'exception_type' => $definition->type,
                'start_time' => $definition->interval?->start,
                'end_time' => $definition->interval?->end,
                'reason' => $definition->reason,
            ])->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'specialist.schedule.exception.updated',
                targetType: ScheduleException::class,
                targetId: (string) $locked->getKey(),
                metadata: [
                    'exception_type' => $definition->type->value,
                    'specialist_id' => $newSpecialist->getKey(),
                    'source' => 'crm',
                ],
            );

            if ($impact->hasConflicts()) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'schedule.mutation.acknowledged',
                    targetType: ScheduleException::class,
                    targetId: (string) $locked->getKey(),
                    metadata: [
                        'source' => 'crm',
                        'mutation' => 'schedule_exception',
                        'affected_booking_count' => $impact->count(),
                        'impact_digest' => $impact->digest,
                    ],
                );
            }

            return $locked->refresh();
        });
    }

    private function ensureNoOverlap(
        int $organizationId,
        int $specialistId,
        int $exceptionId,
        ScheduleExceptionDefinition $definition,
    ): void {
        $existing = ScheduleException::query()
            ->where('organization_id', $organizationId)
            ->where('specialist_id', $specialistId)
            ->where('exception_date', $definition->date)
            ->where('is_active', true)
            ->where('id', '<>', $exceptionId)
            ->get();

        foreach ($existing as $exception) {
            if ($definition->type === ScheduleExceptionType::DayOff
                || $exception->exception_type === ScheduleExceptionType::DayOff
                || $this->overlaps(
                    $definition->interval?->startMinutes(),
                    $definition->interval?->endMinutes(),
                    $exception->wallClockInterval()?->startMinutes(),
                    $exception->wallClockInterval()?->endMinutes(),
                )) {
                throw ValidationException::withMessages([
                    'exception_date' => 'The schedule exception overlaps an existing exception.',
                ]);
            }
        }
    }

    private function overlaps(?int $start, ?int $end, ?int $otherStart, ?int $otherEnd): bool
    {
        return $start !== null && $end !== null && $otherStart !== null && $otherEnd !== null
            && $start < $otherEnd && $end > $otherStart;
    }
}
