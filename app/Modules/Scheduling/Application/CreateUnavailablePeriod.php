<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Scheduling\Domain\ValueObjects\InstantInterval;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateUnavailablePeriod
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
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        ?string $reason = null,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
    ): UnavailablePeriod {
        $organization = $this->context->organization();

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $interval = InstantInterval::from($startsAt, $endsAt);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $organization, $interval, $reason, $specialist, $acknowledgeImpact, $acknowledgedImpactDigest): UnavailablePeriod {
            $lockedSpecialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($specialist->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $overlaps = UnavailablePeriod::query()
                ->where('organization_id', $organization->getKey())
                ->where('specialist_id', $lockedSpecialist->getKey())
                ->where('starts_at', '<', $interval->end)
                ->where('ends_at', '>', $interval->start)
                ->exists();

            if ($overlaps) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The unavailable period overlaps an existing period.',
                ]);
            }

            $impact = $this->impactCalculator->forUnavailablePeriod($lockedSpecialist, $interval->start, $interval->end);
            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);

            $period = new UnavailablePeriod;
            $period->forceFill([
                'organization_id' => $organization->getKey(),
                'specialist_id' => $lockedSpecialist->getKey(),
                'created_by_user_id' => $actor->getKey(),
                'starts_at' => $interval->start,
                'ends_at' => $interval->end,
                'reason' => $reason,
            ]);
            $period->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'specialist.unavailable_period.created',
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
                        'mutation' => 'unavailable_period',
                        'affected_booking_count' => $impact->count(),
                        'impact_digest' => $impact->digest,
                    ],
                );
            }

            return $period->refresh();
        });
    }

    private function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('The unavailable period reason is invalid.');
        }

        return $reason;
    }
}
