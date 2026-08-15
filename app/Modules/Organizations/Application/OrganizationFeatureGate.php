<?php

namespace App\Modules\Organizations\Application;

use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;

class OrganizationFeatureGate
{
    /** @var array<string, bool> */
    private static array $resolved = [];

    public function isEnabled(Organization $organization, OrganizationFeature $feature): bool
    {
        $key = $organization->getKey().':'.$feature->value;

        if (array_key_exists($key, self::$resolved)) {
            return self::$resolved[$key];
        }

        return self::$resolved[$key] = $organization->featureFlags()
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

    public static function invalidate(?int $organizationId = null, ?OrganizationFeature $feature = null): void
    {
        if ($organizationId !== null && $feature !== null) {
            unset(self::$resolved[$organizationId.':'.$feature->value]);
        } else {
            self::$resolved = [];
        }
    }
}
