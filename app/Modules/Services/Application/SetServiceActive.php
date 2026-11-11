<?php

namespace App\Modules\Services\Application;

use App\Models\User;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scheduling\Application\EnsureScheduleMutationImpactAcknowledged;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SetServiceActive
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly OrganizationFeatureGate $features,
        private readonly ScheduleMutationImpactCalculator $impactCalculator,
        private readonly EnsureScheduleMutationImpactAcknowledged $impactAcknowledgement,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        User $actor,
        Service $service,
        bool $isActive,
        bool $acknowledgeImpact = false,
        ?string $acknowledgedImpactDigest = null,
    ): Service {
        $organization = $this->context->organization();

        if ((int) $service->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('The service is outside the current organization.');
        }

        $this->features->authorize($organization, OrganizationFeature::ServiceCatalog);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageServices);

        return DB::transaction(function () use (
            $actor,
            $isActive,
            $organization,
            $service,
            $acknowledgeImpact,
            $acknowledgedImpactDigest,
        ): Service {
            $lockedService = Service::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($service->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((bool) $lockedService->is_active === $isActive) {
                return $lockedService->refresh();
            }

            $impact = $this->impactCalculator->forServiceChange($lockedService, [
                'is_active' => $isActive,
            ]);

            $this->impactAcknowledgement->handle($impact, $acknowledgeImpact, $acknowledgedImpactDigest);

            $lockedService->forceFill(['is_active' => $isActive]);
            $lockedService->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: $isActive ? 'service.restored' : 'service.archived',
                targetType: Service::class,
                targetId: (string) $lockedService->getKey(),
                metadata: ['source' => 'crm'],
            );

            if ($impact->hasConflicts()) {
                $this->audit->handle(
                    organization: $organization,
                    actor: $actor,
                    action: 'schedule.mutation.acknowledged',
                    targetType: Service::class,
                    targetId: (string) $lockedService->getKey(),
                    metadata: [
                        'source' => 'crm',
                        'mutation' => $isActive ? 'service_restore' : 'service_archive',
                        'affected_booking_count' => $impact->count(),
                        'impact_digest' => $impact->digest,
                    ],
                );
            }

            return $lockedService->refresh();
        });
    }
}
