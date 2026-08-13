<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class DeleteScheduleException
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly ScheduleMutationImpactCalculator $impactCalculator,
        private readonly EnsureScheduleMutationImpactAcknowledged $impactAcknowledgement,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        ScheduleException $exception,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
    ): void {
        $organization = $this->context->organization();

        if ((int) $exception->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The schedule exception is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);

        DB::transaction(function () use ($actor, $exception, $organization, $acknowledgeImpact, $acknowledgedImpactDigest): void {
            $lockedSpecialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($exception->specialist_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = ScheduleException::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($exception->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $impact = $this->impactCalculator->forExceptionDeletion($lockedSpecialist, $locked);
            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);
            $locked->delete();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'specialist.schedule.exception.deleted',
                targetType: ScheduleException::class,
                targetId: (string) $exception->getKey(),
                metadata: ['source' => 'crm'],
            );
            if ($impact->hasConflicts()) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'schedule.mutation.acknowledged',
                    targetType: ScheduleException::class,
                    targetId: (string) $exception->getKey(),
                    metadata: [
                        'source' => 'crm',
                        'mutation' => 'schedule_exception_deletion',
                        'affected_booking_count' => $impact->count(),
                        'impact_digest' => $impact->digest,
                    ],
                );
            }
        });
    }
}
