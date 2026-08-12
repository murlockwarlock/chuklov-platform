<?php

namespace App\Modules\Organizations\Application;

use App\Models\User;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

class SetOrganizationFeatureFlag
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, OrganizationFeature $feature, bool $enabled): OrganizationFeatureFlag
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageFeatures);

        return DB::transaction(function () use ($organization, $actor, $feature, $enabled): OrganizationFeatureFlag {
            $flag = $organization->featureFlags()->where('feature_key', $feature->value)->first()
                ?? new OrganizationFeatureFlag;
            $flag->forceFill([
                'organization_id' => $organization->getKey(),
                'feature_key' => $feature->value,
                'enabled' => $enabled,
            ]);
            $flag->save();

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'organization.feature.updated',
                targetType: OrganizationFeatureFlag::class,
                targetId: (string) $flag->getKey(),
                metadata: ['feature_key' => $feature->value, 'enabled' => $enabled],
            );

            return $flag->refresh();
        });
    }
}
