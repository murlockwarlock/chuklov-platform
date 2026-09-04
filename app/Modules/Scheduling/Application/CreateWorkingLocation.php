<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use App\Modules\Scheduling\Domain\ValueObjects\WorkingLocationDefinition;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

final readonly class CreateWorkingLocation
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        string $name,
        string $address,
        string $timezone,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $mapUrl = null,
        bool $isActive = true,
        bool $isDefaultOffice = false,
    ): WorkingLocation {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $definition = WorkingLocationDefinition::from($name, $address, $timezone, $latitude, $longitude, $mapUrl, $isActive, $isDefaultOffice);

        return DB::transaction(function () use ($actor, $organization, $definition): WorkingLocation {
            $existingActive = WorkingLocation::query()
                ->where('organization_id', $organization->getKey())
                ->where('is_active', true)
                ->lockForUpdate()
                ->exists();
            $isDefault = $definition->isActive && ($definition->isDefaultOffice || ! $existingActive);
            if ($isDefault) {
                WorkingLocation::query()
                    ->where('organization_id', $organization->getKey())
                    ->update(['is_default_office' => false, 'updated_at' => now()]);
            }

            $location = new WorkingLocation;
            $location->forceFill([
                'organization_id' => $organization->getKey(),
                ...$definition->attributes(),
                'is_default_office' => $isDefault,
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'working_location.created',
                targetType: WorkingLocation::class,
                targetId: (string) $location->getKey(),
                metadata: ['is_default_office' => $isDefault],
            );

            return $location->refresh();
        });
    }
}
