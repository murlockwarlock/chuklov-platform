<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\ClientEmailAuthChallenge;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientEmailAuthChallenge>
 */
class ClientEmailAuthChallengeFactory extends Factory
{
    protected $model = ClientEmailAuthChallenge::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'code_hash' => bcrypt('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ClientEmailAuthChallenge $challenge): ClientEmailAuthChallenge => $challenge->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }
}
