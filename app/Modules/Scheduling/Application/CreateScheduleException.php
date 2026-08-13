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

class CreateScheduleException
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
        Specialist $specialist,
        array $attributes,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
    ): ScheduleException {
        $organization = $this->context->organization();

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $definition = ScheduleExceptionDefinition::from($attributes);

        return DB::transaction(function () use ($actor, $organization, $definition, $specialist, $acknowledgeImpact, $acknowledgedImpactDigest): ScheduleException {
            $lockedSpecialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($specialist->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $impact = $this->impactCalculator->forException($lockedSpecialist, $definition);
            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);
            $this->ensureNoOverlap($organization->getKey(), $lockedSpecialist->getKey(), $definition);

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
            ]);
            $exception->save();

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
            $this->recordImpactAcknowledgement($actor, $exception, $impact);

            return $exception->refresh();
        });
    }

    private function recordImpactAcknowledgement(
        User $actor,
        ScheduleException $exception,
        ScheduleMutationImpact $impact,
    ): void {
        if (! $impact->hasConflicts()) {
            return;
        }

        $this->audit->handle(
            organization: $this->context->organization(),
            actor: $actor,
            action: 'schedule.mutation.acknowledged',
            targetType: ScheduleException::class,
            targetId: (string) $exception->getKey(),
            metadata: [
                'source' => 'crm',
                'mutation' => 'schedule_exception',
                'affected_booking_count' => $impact->count(),
                'impact_digest' => $impact->digest,
            ],
        );
    }

    private function ensureNoOverlap(
        int $organizationId,
        int $specialistId,
        ScheduleExceptionDefinition $definition,
    ): void {
        $existing = ScheduleException::query()
            ->where('organization_id', $organizationId)
            ->where('specialist_id', $specialistId)
            ->where('exception_date', $definition->date)
            ->where('is_active', true)
            ->get();

        foreach ($existing as $exception) {
            if ($definition->type === ScheduleExceptionType::DayOff
                || $exception->exception_type === ScheduleExceptionType::DayOff
                || $this->overlaps($definition->interval?->startMinutes(), $definition->interval?->endMinutes(), $exception->wallClockInterval()?->startMinutes(), $exception->wallClockInterval()?->endMinutes())) {
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
