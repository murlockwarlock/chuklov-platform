<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientConsent>
 */
class ClientConsentFactory extends Factory
{
    protected $model = ClientConsent::class;

    public function definition(): array
    {
        return [
            'subject' => ConsentSubject::Privacy->value,
            'version' => '2026-01',
            'legal_document_id' => null,
            'is_required' => true,
            'granted' => true,
            'evidence' => 'web',
            'recorded_at' => now(),
        ];
    }

    public function forOrganization(Organization $organization): static
    {
        return $this->afterMaking(fn (ClientConsent $consent): ClientConsent => $consent->forceFill([
            'organization_id' => $organization->getKey(),
        ]));
    }

    public function forClient(Client $client): static
    {
        return $this->afterMaking(fn (ClientConsent $consent): ClientConsent => $consent->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
        ]));
    }
}
