<?php

namespace App\Modules\Specialists\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Application\EnsureScheduleMutationImpactAcknowledged;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SetSpecialistActive
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
        Specialist $specialist,
        bool $isActive,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
    ): Specialist {
        $organization = $this->context->organization();

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSpecialists);

        return DB::transaction(function () use ($actor, $isActive, $organization, $specialist, $acknowledgeImpact, $acknowledgedImpactDigest): Specialist {
            $lockedSpecialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($specialist->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((bool) $lockedSpecialist->is_active === $isActive) {
                return $lockedSpecialist->refresh();
            }

            $impact = $this->impactCalculator->forSpecialistChange(
                specialist: $lockedSpecialist,
                newIsActive: $isActive,
                newTimezone: $lockedSpecialist->timezone,
            );

            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);

            $lockedSpecialist->forceFill(['is_active' => $isActive]);
            $lockedSpecialist->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: $isActive ? 'specialist.activated' : 'specialist.deactivated',
                targetType: Specialist::class,
                targetId: (string) $lockedSpecialist->getKey(),
                metadata: ['source' => 'crm'],
            );
            if ($impact->hasConflicts()) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'schedule.mutation.acknowledged',
                    targetType: Specialist::class,
                    targetId: (string) $lockedSpecialist->getKey(),
                    metadata: [
                        'source' => 'crm',
                        'mutation' => 'specialist_deactivation',
                        'affected_booking_count' => $impact->count(),
                        'impact_digest' => $impact->digest,
                    ],
                );
            }

            return $lockedSpecialist->refresh();
        });
    }
}
