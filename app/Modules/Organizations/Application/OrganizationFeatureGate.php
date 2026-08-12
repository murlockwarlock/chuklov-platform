<?php

namespace App\Modules\Organizations\Application;

use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;

class OrganizationFeatureGate
{
    public function isEnabled(Organization $organization, OrganizationFeature $feature): bool
    {
        return $organization->featureFlags()
            ->where('feature_key', $feature->value)
            ->where('enabled', true)
            ->exists();
    }

    public function authorize(Organization $organization, OrganizationFeature $feature): void
    {
        if (! $this->isEnabled($organization, $feature)) {
            throw new AuthorizationException('The organization feature is not enabled.');
        }
    }
}
