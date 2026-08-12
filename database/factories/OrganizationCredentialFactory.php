<?php

namespace Database\Factories;

use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationCredential>
 */
class OrganizationCredentialFactory extends Factory
{
    protected $model = OrganizationCredential::class;

    public function definition(): array
    {
        return [
            'provider' => 'test-provider',
            'credential_name' => 'default',
            'status' => CredentialStatus::Active->value,
            'last_rotated_at' => now(),
        ];
    }

    protected function configure(): static
    {
        return $this->afterMaking(fn (OrganizationCredential $credential): OrganizationCredential => $credential->forceFill([
            'credentials' => ['token' => 'test-secret'],
        ]));
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (OrganizationCredential $credential): OrganizationCredential => $credential->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }
}
