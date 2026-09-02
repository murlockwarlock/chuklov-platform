<?php

namespace App\Modules\Specialists\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Scheduling\Application\EnsureScheduleMutationImpactAcknowledged;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistNotificationSettings;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UpdateSpecialist
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly ScheduleMutationImpactCalculator $impactCalculator,
        private readonly EnsureScheduleMutationImpactAcknowledged $impactAcknowledgement,
        private readonly RecordAuditEvent $audit,
        private readonly SyncSpecialistTelegramIdentity $telegramIdentity,
    ) {}

    public function handle(
        User $actor,
        Specialist $specialist,
        string $displayName,
        bool $isActive,
        ?string $timezone = null,
        ?int $staffUserId = null,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
        ?SpecialistNotificationSettings $notificationSettings = null,
    ): Specialist {
        $organization = $this->context->organization();

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSpecialists);
        $profile = SpecialistProfile::from($displayName, $timezone);

        return DB::transaction(function () use ($actor, $isActive, $organization, $profile, $specialist, $staffUserId, $acknowledgeImpact, $acknowledgedImpactDigest, $notificationSettings): Specialist {
            $lockedSpecialist = Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($specialist->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureStaffMembership($organization->getKey(), $staffUserId);
            $oldStaffUserId = $lockedSpecialist->staff_user_id;
            $oldIsActive = (bool) $lockedSpecialist->is_active;
            /** @var list<string> $changedFields */
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

            if ($notificationSettings !== null
                && (bool) $lockedSpecialist->notifications_enabled !== $notificationSettings->enabled) {
                $changedFields[] = 'notifications_enabled';
            }

            $impact = $this->impactCalculator->forSpecialistChange(
                specialist: $lockedSpecialist,
                newIsActive: $isActive,
                newTimezone: $profile->timezone,
            );

            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);

            $lockedSpecialist->forceFill([
                ...$profile->attributes(),
                'is_active' => $isActive,
                'notifications_enabled' => $notificationSettings->enabled ?? $lockedSpecialist->notifications_enabled,
                'staff_user_id' => $staffUserId,
            ]);
            $lockedSpecialist->save();

            if ($notificationSettings !== null) {
                $this->telegramIdentity->handle(
                    actor: $actor,
                    organization: $organization,
                    specialist: $lockedSpecialist,
                    telegramId: $notificationSettings->telegramId,
                );
            }

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

            if (in_array('notifications_enabled', $changedFields, true)) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'specialist.notifications.updated',
                    targetType: Specialist::class,
                    targetId: (string) $lockedSpecialist->getKey(),
                    metadata: ['enabled' => $notificationSettings?->enabled],
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
                        'mutation' => $this->impactMutationLabel($oldIsActive, $isActive, $changedFields),
                        'affected_booking_count' => $impact->count(),
                        'impact_digest' => $impact->digest,
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

    /** @param list<string> $changedFields */
    private function impactMutationLabel(bool $oldIsActive, bool $newIsActive, array $changedFields): string
    {
        $timezoneChanged = in_array('timezone', $changedFields, true);
        $deactivated = $oldIsActive && ! $newIsActive;

        return match (true) {
            $deactivated && $timezoneChanged => 'specialist_deactivation_and_timezone',
            $deactivated => 'specialist_deactivation',
            $timezoneChanged => 'specialist_timezone',
            default => 'specialist_schedule_change',
        };
    }
}
