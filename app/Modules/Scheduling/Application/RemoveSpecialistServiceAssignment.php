<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class RemoveSpecialistServiceAssignment
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, SpecialistServiceAssignment $assignment): void
    {
        $organization = $this->context->organization();

        if ((int) $assignment->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The assignment is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);

        DB::transaction(function () use ($actor, $assignment, $organization): void {
            Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($assignment->specialist_id)
                ->lockForUpdate()
                ->firstOrFail();
            Service::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($assignment->service_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAssignment = SpecialistServiceAssignment::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedAssignment->delete();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'specialist.service.unassigned',
                targetType: SpecialistServiceAssignment::class,
                targetId: (string) $assignment->getKey(),
                metadata: ['source' => 'crm'],
            );
        });
    }
}
