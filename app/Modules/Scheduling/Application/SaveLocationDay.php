<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\LocationDay;
use App\Modules\Scheduling\Domain\ValueObjects\LocationDayDefinition;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class SaveLocationDay
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private RecordAuditEvent $audit,
        private BookingLocationResolver $locations,
    ) {}

    public function handle(
        User $actor,
        ?LocationDay $locationDay,
        string $areaName,
        ?int $weekday,
        ?string $specificDate,
        string $startTime,
        string $endTime,
        string $timezone,
        bool $isActive,
        ?string $notes,
    ): LocationDay {
        $organization = $this->context->organization();
        if ($locationDay !== null && (int) $locationDay->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The location day is outside the current organization.');
        }
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $definition = LocationDayDefinition::from($areaName, $weekday, $specificDate, $startTime, $endTime, $timezone, $isActive, $notes);

        return DB::transaction(function () use ($actor, $organization, $locationDay, $definition): LocationDay {
            $record = $locationDay === null ? new LocationDay : LocationDay::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($locationDay->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->locations->ensureTimezoneCompatibility($definition, $locationDay?->getKey());
            $record->forceFill([
                'organization_id' => $organization->getKey(),
                ...$definition->attributes(),
            ])->save();
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: $locationDay === null ? 'location_day.created' : 'location_day.updated',
                targetType: LocationDay::class,
                targetId: (string) $record->getKey(),
                metadata: ['area_name' => $definition->areaName, 'is_active' => $definition->isActive],
            );

            return $record->refresh();
        });
    }
}
