<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationFeatureFlag>
 */
class OrganizationFeatureFlagFactory extends Factory
{
    protected $model = OrganizationFeatureFlag::class;

    public function definition(): array
    {
        return [
            'feature_key' => 'client_records',
            'enabled' => true,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (OrganizationFeatureFlag $flag): OrganizationFeatureFlag => $flag->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }
}
