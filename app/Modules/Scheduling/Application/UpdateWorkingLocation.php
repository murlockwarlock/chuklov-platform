<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\WorkingLocation;
use App\Modules\Scheduling\Domain\ValueObjects\WorkingLocationDefinition;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateWorkingLocation
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        WorkingLocation $location,
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
        if ((int) $location->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The working location is outside the current organization.');
        }
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $definition = WorkingLocationDefinition::from($name, $address, $timezone, $latitude, $longitude, $mapUrl, $isActive, $isDefaultOffice);

        return DB::transaction(function () use ($actor, $organization, $location, $definition): WorkingLocation {
            $locked = WorkingLocation::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($location->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($definition->isActive && $definition->isDefaultOffice) {
                WorkingLocation::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('id', '<>', $locked->getKey())
                    ->update(['is_default_office' => false, 'updated_at' => now()]);
                $locked->forceFill($definition->attributes())->save();
            } else {
                $locked->forceFill($definition->attributes())->save();
                $locked->forceFill(['is_default_office' => false])->save();
                $defaultExists = WorkingLocation::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('is_active', true)
                    ->where('is_default_office', true)
                    ->exists();
                if (! $defaultExists) {
                    $replacement = WorkingLocation::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('is_active', true)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();
                    $replacement?->forceFill(['is_default_office' => true])->save();
                }
            }
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'working_location.updated',
                targetType: WorkingLocation::class,
                targetId: (string) $locked->getKey(),
                metadata: ['is_active' => $definition->isActive, 'is_default_office' => (bool) $locked->is_default_office],
            );

            return $locked->refresh();
        });
    }
}
