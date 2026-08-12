<?php

namespace App\Modules\Scheduling\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Scheduling\Domain\ValueObjects\SpecialistScheduleDefinition;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SetSpecialistWorkingHours
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return Collection<int, SpecialistWorkingHour>
     */
    public function handle(User $actor, Specialist $specialist, array $definitions): Collection
    {
        $organization = $this->context->organization();

        if ((int) $specialist->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The specialist is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageScheduling);
        $schedule = SpecialistScheduleDefinition::from($definitions);

        return DB::transaction(function () use ($actor, $organization, $schedule, $specialist): Collection {
            Specialist::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($specialist->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            SpecialistWorkingHour::query()
                ->where('organization_id', $organization->getKey())
                ->where('specialist_id', $specialist->getKey())
                ->delete();

            foreach ($schedule->attributes() as $attributes) {
                $workingHour = new SpecialistWorkingHour;
                $workingHour->forceFill([
                    'organization_id' => $organization->getKey(),
                    'specialist_id' => $specialist->getKey(),
                    ...$attributes,
                    'is_active' => true,
                ]);
                $workingHour->save();
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'specialist.schedule.updated',
                targetType: Specialist::class,
                targetId: (string) $specialist->getKey(),
                metadata: [
                    'weekday_count' => count(array_unique(array_column($schedule->attributes(), 'weekday'))),
                    'interval_count' => count($schedule->intervals),
                ],
            );

            return SpecialistWorkingHour::query()
                ->where('organization_id', $organization->getKey())
                ->where('specialist_id', $specialist->getKey())
                ->orderBy('weekday')
                ->orderBy('start_time')
                ->get();
        });
    }
}
