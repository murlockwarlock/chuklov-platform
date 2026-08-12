<?php

namespace App\Modules\Specialists\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Specialists\Domain\ValueObjects\SpecialistProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CreateSpecialist
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        string $displayName,
        bool $isActive = true,
        ?string $timezone = null,
        ?int $staffUserId = null,
    ): Specialist {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSpecialists);
        $profile = SpecialistProfile::from($displayName, $timezone);

        return DB::transaction(function () use ($actor, $isActive, $organization, $profile, $staffUserId): Specialist {
            $this->ensureStaffMembership($organization->getKey(), $staffUserId);
            $specialist = new Specialist;
            $specialist->forceFill([
                'organization_id' => $organization->getKey(),
                ...$profile->attributes(),
                'is_active' => $isActive,
                'staff_user_id' => $staffUserId,
            ]);
            $specialist->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'specialist.created',
                targetType: Specialist::class,
                targetId: (string) $specialist->getKey(),
                metadata: [
                    'source' => 'application',
                    'is_active' => $isActive,
                    'timezone_set' => $profile->timezone !== null,
                ],
            );

            if ($staffUserId !== null) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'specialist.linked',
                    targetType: Specialist::class,
                    targetId: (string) $specialist->getKey(),
                    metadata: ['user_id' => $staffUserId],
                );
            }

            return $specialist->refresh();
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
