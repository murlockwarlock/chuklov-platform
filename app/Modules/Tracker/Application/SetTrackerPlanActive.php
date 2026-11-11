<?php

namespace App\Modules\Tracker\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SetTrackerPlanActive
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, TrackerPlan $plan, bool $isActive): TrackerPlan
    {
        $organization = $this->context->organization();

        if ((int) $plan->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The tracker plan is outside the current organization.');
        }

        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageSettings);

        return DB::transaction(function () use ($actor, $organization, $plan, $isActive): TrackerPlan {
            $locked = TrackerPlan::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($plan->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((bool) $locked->is_active !== $isActive) {
                $locked->forceFill([
                    'is_active' => $isActive,
                    'archived_at' => $isActive ? null : ($locked->archived_at ?? CarbonImmutable::now('UTC')),
                ])->save();

                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: $isActive ? 'tracker.plan.restored' : 'tracker.plan.archived',
                    targetType: TrackerPlan::class,
                    targetId: (string) $locked->getKey(),
                    metadata: ['source' => 'crm'],
                );
            }

            return $locked->refresh();
        });
    }
}
