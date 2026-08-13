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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignSpecialistToService
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, Specialist $specialist, Service $service): SpecialistServiceAssignment
    {
        $organization = $this->context->organization();

        if ((int) $specialist->organization_id !== $organization->getKey()
            || (int) $service->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist and service must belong to the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);

        return DB::transaction(function () use ($actor, $organization, $specialist, $service): SpecialistServiceAssignment {
            Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($specialist->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            Service::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($service->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (SpecialistServiceAssignment::query()
                ->where('organization_id', $organization->getKey())
                ->where('specialist_id', $specialist->getKey())
                ->where('service_id', $service->getKey())
                ->exists()) {
                throw ValidationException::withMessages([
                    'assignment' => 'This specialist is already assigned to the service.',
                ]);
            }

            $assignment = new SpecialistServiceAssignment;
            $assignment->forceFill([
                'organization_id' => $organization->getKey(),
                'specialist_id' => $specialist->getKey(),
                'service_id' => $service->getKey(),
            ]);

            try {
                $assignment->save();
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw ValidationException::withMessages([
                        'assignment' => 'This specialist is already assigned to the service.',
                    ]);
                }

                throw $exception;
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'specialist.service.assigned',
                targetType: SpecialistServiceAssignment::class,
                targetId: (string) $assignment->getKey(),
                metadata: ['source' => 'crm'],
            );

            return $assignment->refresh();
        });
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505' || ($exception->errorInfo[0] ?? null) === '23505';
    }
}
