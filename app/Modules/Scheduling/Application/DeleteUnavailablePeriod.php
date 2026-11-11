<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteUnavailablePeriod
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
        UnavailablePeriod $period,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
    ): void {
        $organization = $this->context->organization();

        if ((int) $period->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The unavailable period is outside the current organization.');
        }

        if ($period->b2b_sales_call_id !== null) {
            throw ValidationException::withMessages([
                'period' => 'A B2B sales call calendar block can only be changed through the sales call.',
            ]);
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);

        DB::transaction(function () use ($actor, $period, $organization, $acknowledgeImpact, $acknowledgedImpactDigest): void {
            $locked = UnavailablePeriod::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($period->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->b2b_sales_call_id !== null) {
                throw ValidationException::withMessages([
                    'period' => 'A B2B sales call calendar block can only be changed through the sales call.',
                ]);
            }

            $lockedSpecialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($locked->specialist_id)
                ->lockForUpdate()
                ->firstOrFail();
            $impact = $this->impactCalculator->forUnavailablePeriod(
                specialist: $lockedSpecialist,
                startsAt: $locked->starts_at,
                endsAt: $locked->ends_at,
            );
            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);

            $locked->delete();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'specialist.unavailable_period.deleted',
                targetType: UnavailablePeriod::class,
                targetId: (string) $period->getKey(),
                metadata: ['source' => 'crm'],
            );
            if ($impact->hasConflicts()) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'schedule.mutation.acknowledged',
                    targetType: UnavailablePeriod::class,
                    targetId: (string) $period->getKey(),
                    metadata: [
                        'source' => 'crm',
                        'mutation' => 'unavailable_period_deletion',
                        'affected_booking_count' => $impact->count(),
                        'impact_digest' => $impact->digest,
                    ],
                );
            }
        });
    }
}
