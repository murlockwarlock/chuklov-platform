<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    protected $model = AuditEvent::class;

    public function definition(): array
    {
        return [
            'action' => 'test.action',
            'target_type' => null,
            'target_id' => null,
            'metadata' => [],
            'occurred_at' => now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (AuditEvent $event): AuditEvent => $event->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function by(User $user): static
    {
        return $this->afterMaking(fn (AuditEvent $event): AuditEvent => $event->forceFill([
            'actor_user_id' => $user->getKey(),
        ]));
    }
}
