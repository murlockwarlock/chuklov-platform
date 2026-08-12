<?php

namespace Tests\Integration;

use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MilestoneTwoDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_telegram_identity_is_unique_inside_an_organization(): void
    {
        $organization = Organization::factory()->create();
        $firstClient = Client::factory()->forOrganization($organization)->create();
        $secondClient = Client::factory()->forOrganization($organization)->create();

        ClientChannelIdentity::factory()->forClient($firstClient)->create([
            'channel' => 'telegram',
            'external_id' => 'duplicate-telegram-id',
        ]);

        $duplicate = ClientChannelIdentity::factory()->forClient($secondClient)->make([
            'channel' => 'telegram',
            'external_id' => 'duplicate-telegram-id',
        ]);

        $this->expectException(QueryException::class);

        $duplicate->save();
    }

    public function test_onboarding_and_conversation_records_cannot_cross_organization_clients(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();

        $onboarding = new ClientOnboarding;
        $onboarding->forceFill([
            'organization_id' => $organization->id,
            'client_id' => $otherClient->id,
            'flow_version' => 'm2-v1',
            'current_stage' => 'contacts',
            'data' => [],
        ]);

        $this->expectException(QueryException::class);

        $onboarding->save();
    }
}
