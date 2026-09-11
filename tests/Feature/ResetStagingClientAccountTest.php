<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\User;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Identity\Application\ResetStagingClientAccount;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Domain\Models\ClientReferralIdentity;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class ResetStagingClientAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_operator_can_reset_a_staging_client_from_the_client_card(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        ClientChannelIdentity::factory()->forClient($client)->create();
        ClientOnboarding::factory()->forClient($client)->create();
        ClientReferralIdentity::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'public_code' => 'reset-test-code',
        ]);
        DB::table('client_acquisition_registrations')->insert([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'session_hash' => str_repeat('a', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertActionVisible('resetStagingAccount')
            ->callAction('resetStagingAccount')
            ->assertNotified('Аккаунт сброшен для повторного теста')
            ->assertRedirect(ClientResource::getUrl('index'));

        self::assertDatabaseMissing('clients', ['id' => $client->getKey()]);
        self::assertDatabaseMissing('client_channel_identities', ['client_id' => $client->getKey()]);
        self::assertDatabaseMissing('client_onboardings', ['client_id' => $client->getKey()]);
        self::assertDatabaseMissing('client_referral_identities', ['client_id' => $client->getKey()]);
        self::assertDatabaseMissing('client_acquisition_registrations', ['client_id' => $client->getKey()]);
        self::assertDatabaseHas('audit_events', [
            'organization_id' => $organization->getKey(),
            'actor_user_id' => $admin->getKey(),
            'action' => 'client.staging_account.reset',
            'target_id' => (string) $client->getKey(),
        ]);
    }

    public function test_reset_rejects_a_client_with_protected_history(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        ClientAttribution::forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'source_type' => 'manual',
            'source' => 'CRM',
            'capture_channel' => 'crm',
            'captured_at' => now(),
            'accepted_at' => now(),
        ]);

        try {
            app(ResetStagingClientAccount::class)->handle($admin, $client);
            self::fail('The reset should be blocked for a client with protected history.');
        } catch (ValidationException) {
            self::assertDatabaseHas('clients', ['id' => $client->getKey()]);
        }
    }

    public function test_reset_rejects_a_client_from_another_organization(): void
    {
        [$organization, $admin] = $this->fixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();

        try {
            app(ResetStagingClientAccount::class)->handle($admin, $foreignClient);
            self::fail('The reset should be blocked for a foreign client.');
        } catch (AuthorizationException) {
            self::assertDatabaseHas('clients', [
                'organization_id' => $foreignOrganization->getKey(),
                'id' => $foreignClient->getKey(),
            ]);
        }

        self::assertDatabaseHas('organizations', ['id' => $organization->getKey()]);
    }

    public function test_reset_is_rejected_outside_safe_environments(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $this->app->detectEnvironment(static fn (): string => 'production');

        try {
            app(ResetStagingClientAccount::class)->handle($admin, $client);
            self::fail('The reset should be unavailable in production.');
        } catch (AuthorizationException) {
            self::assertDatabaseHas('clients', [
                'organization_id' => $organization->getKey(),
                'id' => $client->getKey(),
            ]);
        }
    }

    /** @return array{Organization, User, Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }
}
