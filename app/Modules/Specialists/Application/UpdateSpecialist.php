<?php

namespace App\Modules\Specialists\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSpecialist
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly ScheduleMutationImpactCalculator $impactCalculator,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        Specialist $specialist,
        string $displayName,
        bool $isActive,
        ?string $timezone = null,
        ?int $staffUserId = null,
        bool $acknowledgeImpact = false,
    ): Specialist {
        $organization = $this->context->organization();

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSpecialists);
        $profile = SpecialistProfile::from($displayName, $timezone);

        return DB::transaction(function () use ($actor, $isActive, $organization, $profile, $specialist, $staffUserId, $acknowledgeImpact): Specialist {
            $lockedSpecialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($specialist->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureStaffMembership($organization->getKey(), $staffUserId);
            $oldStaffUserId = $lockedSpecialist->staff_user_id;
            $oldIsActive = (bool) $lockedSpecialist->is_active;
            $changedFields = [];

            foreach ($profile->attributes() as $field => $value) {
                if ($lockedSpecialist->getAttribute($field) !== $value) {
                    $changedFields[] = $field;
                }
            }

            if ($oldIsActive !== $isActive) {
                $changedFields[] = 'is_active';
            }

            if ((int) ($oldStaffUserId ?? 0) !== (int) ($staffUserId ?? 0)) {
                $changedFields[] = 'staff_user_id';
            }

            $impact = $this->impactCalculator->forSpecialistChange(
                specialist: $lockedSpecialist,
                newIsActive: $isActive,
                newTimezone: $profile->timezone,
            );

            if ($impact->hasConflicts() && ! $acknowledgeImpact) {
                throw ValidationException::withMessages([
                    'schedule_impact' => $impact->count().' future booking(s) are affected. Review and acknowledge the impact before saving.',
                ]);
            }

            $lockedSpecialist->forceFill([
                ...$profile->attributes(),
                'is_active' => $isActive,
                'staff_user_id' => $staffUserId,
            ]);
            $lockedSpecialist->save();

            if (array_intersect($changedFields, ['display_name', 'timezone']) !== []) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'specialist.updated',
                    targetType: Specialist::class,
                    targetId: (string) $lockedSpecialist->getKey(),
                    metadata: ['fields' => implode(',', $changedFields)],
                );
            }

            if ($oldIsActive !== $isActive) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: $isActive ? 'specialist.activated' : 'specialist.deactivated',
                    targetType: Specialist::class,
                    targetId: (string) $lockedSpecialist->getKey(),
                    metadata: ['source' => 'crm'],
                );
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
                        'mutation' => 'specialist_deactivation',
                        'affected_booking_count' => $impact->count(),
                    ],
                );
            }

            if ((int) ($oldStaffUserId ?? 0) !== (int) ($staffUserId ?? 0)) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: $staffUserId === null ? 'specialist.unlinked' : 'specialist.linked',
                    targetType: Specialist::class,
                    targetId: (string) $lockedSpecialist->getKey(),
                    metadata: $staffUserId === null ? [] : ['user_id' => $staffUserId],
                );
            }

            return $lockedSpecialist->refresh();
        });
    }

    private function ensureStaffMembership(int $organizationId, ?int $staffUserId): void
    {
        if ($staffUserId === null) {
            return;
        }

        $isMember = OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $staffUserId)
            ->active()
            ->exists();

        if (! $isMember) {
            throw new AuthorizationException('The staff user is not an active member of this organization.');
        }
    }
}
