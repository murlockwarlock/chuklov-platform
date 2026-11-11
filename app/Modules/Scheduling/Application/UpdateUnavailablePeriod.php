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

final class UpdateUnavailablePeriod
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
        Specialist $specialist,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        ?string $reason = null,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
    ): UnavailablePeriod {
        $organization = $this->context->organization();

        if ((int) $period->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The unavailable period is outside the current organization.');
        }

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        if ($period->b2b_sales_call_id !== null) {
            throw ValidationException::withMessages([
                'period' => 'A B2B sales call calendar block can only be changed through the sales call.',
            ]);
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);

        try {
            $interval = InstantInterval::from($startsAt, $endsAt);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'ends_at' => __('Окончание периода должно быть позже начала.'),
            ]);
        }

        $reason = $this->reason($reason);

        return DB::transaction(function () use (
            $actor,
            $organization,
            $period,
            $specialist,
            $interval,
            $reason,
            $acknowledgeImpact,
            $acknowledgedImpactDigest,
        ): UnavailablePeriod {
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

            $specialistIds = array_values(array_unique([
                (int) $locked->specialist_id,
                (int) $specialist->getKey(),
            ]));
            sort($specialistIds);
            $specialists = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereIn('id', $specialistIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $currentSpecialist = $specialists->get((int) $locked->specialist_id);
            $targetSpecialist = $specialists->get((int) $specialist->getKey());
            if (! $currentSpecialist instanceof Specialist || ! $targetSpecialist instanceof Specialist) {
                throw new AuthorizationException('The unavailable period specialist is outside the current organization.');
            }

            $overlaps = UnavailablePeriod::query()
                ->where('organization_id', $organization->getKey())
                ->where('specialist_id', $targetSpecialist->getKey())
                ->where('id', '<>', $locked->getKey())
                ->where('starts_at', '<', $interval->end)
                ->where('ends_at', '>', $interval->start)
                ->exists();

            if ($overlaps) {
                throw ValidationException::withMessages([
                    'starts_at' => 'The unavailable period overlaps an existing period.',
                ]);
            }

            $impact = $this->impactCalculator->forUnavailablePeriodUpdate(
                currentSpecialist: $currentSpecialist,
                currentStartsAt: $locked->starts_at,
                currentEndsAt: $locked->ends_at,
                targetSpecialist: $targetSpecialist,
                targetStartsAt: $interval->start,
                targetEndsAt: $interval->end,
            );
            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);

            $locked->forceFill([
                'specialist_id' => $targetSpecialist->getKey(),
                'starts_at' => $interval->start,
                'ends_at' => $interval->end,
                'reason' => $reason,
            ])->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'specialist.unavailable_period.updated',
                targetType: UnavailablePeriod::class,
                targetId: (string) $locked->getKey(),
                metadata: [
                    'source' => 'crm',
                    'specialist_id' => $targetSpecialist->getKey(),
                ],
            );

            if ($impact->hasConflicts()) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'schedule.mutation.acknowledged',
                    targetType: UnavailablePeriod::class,
                    targetId: (string) $locked->getKey(),
                    metadata: [
                        'source' => 'crm',
                        'mutation' => 'unavailable_period',
                        'affected_booking_count' => $impact->count(),
                        'impact_digest' => $impact->digest,
                    ],
                );
            }

            return $locked->refresh();
        });
    }

    private function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = trim($reason);

        if ($reason === '') {
            return null;
        }

        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => __('Причина не должна превышать 500 символов.'),
            ]);
        }

        return $reason;
    }
}
