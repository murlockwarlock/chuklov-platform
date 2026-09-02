<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\User;
use App\Modules\Broadcasts\Application\BroadcastEligibilityPolicy;
use App\Modules\Identity\Application\RecordClientConsent;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Security\Domain\Models\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

final class ClientMarketingConsentCrmTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_operator_can_record_and_revoke_marketing_consent_with_immediate_state_refresh(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertActionVisible('grantMarketingConsent')
            ->assertActionVisible('revokeMarketingConsent')
            ->assertSee('Маркетинговые рассылки')
            ->assertSee('Согласие не зафиксировано')
            ->callAction('grantMarketingConsent', [
                'version' => 'marketing-2026-01',
                'evidence' => 'telegram',
                'confirmed' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Согласие на рассылки зафиксировано')
            ->assertSee('Согласие есть')
            ->assertSee('Сообщение клиента в Telegram')
            ->assertSee('Версия: marketing-2026-01')
            ->assertSee('Кем: '.$admin->name);

        $granted = ClientConsent::query()->where('client_id', $client->getKey())->sole();
        self::assertTrue($granted->granted);
        self::assertSame('marketing-2026-01', $granted->version);
        self::assertSame('telegram', $granted->evidence);
        self::assertSame($admin->getKey(), $granted->recorded_by_user_id);

        $component
            ->callAction('revokeMarketingConsent', [
                'version' => 'marketing-2026-01',
                'evidence' => 'telegram',
                'confirmed' => true,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Согласие на рассылки отозвано')
            ->assertSee('Согласие отозвано');

        self::assertCount(2, ClientConsent::query()->where('client_id', $client->getKey())->get());
        self::assertTrue($granted->fresh()->granted);
        self::assertFalse(ClientConsent::query()->where('client_id', $client->getKey())->latest('id')->value('granted'));

        $audit = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('action', 'client.consent.recorded')
            ->latest('id')
            ->firstOrFail();

        self::assertSame(ClientConsent::class, $audit->target_type);
        self::assertSame('marketing', $audit->metadata['subject']);
        self::assertSame('marketing-2026-01', $audit->metadata['version']);
        self::assertFalse($audit->metadata['granted']);
        self::assertSame('telegram', $audit->metadata['evidence']);
    }

    public function test_confirmation_is_required_before_recording_marketing_consent(): void
    {
        [, $admin, $client] = $this->fixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->callAction('grantMarketingConsent', [
                'version' => 'marketing-2026-01',
                'evidence' => 'crm',
                'confirmed' => false,
            ])
            ->assertHasFormErrors(['confirmed']);

        self::assertSame(0, ClientConsent::query()->count());
    }

    public function test_latest_marketing_consent_uses_recorded_at_then_id(): void
    {
        [, $admin, $client] = $this->fixture();
        $timestamp = Carbon::parse('2026-01-01 12:00:00');
        ClientConsent::factory()->forClient($client)->create([
            'subject' => ConsentSubject::Marketing->value,
            'is_required' => false,
            'granted' => true,
            'version' => 'older',
            'recorded_at' => $timestamp,
        ]);
        ClientConsent::factory()->forClient($client)->create([
            'subject' => ConsentSubject::Marketing->value,
            'is_required' => false,
            'granted' => false,
            'version' => 'latest',
            'recorded_at' => $timestamp,
        ]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertSee('Согласие отозвано')
            ->assertSee('Версия: latest');
    }

    public function test_record_consent_actions_are_hidden_without_record_consent_permission(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $authorizer = Mockery::mock(OrganizationAuthorizer::class, [app(OrganizationContext::class)])->makePartial();
        $authorizer->shouldReceive('allows')->andReturnUsing(
            fn (User $actor, Organization $currentOrganization, OrganizationPermission $permission): bool => $permission !== OrganizationPermission::RecordConsent,
        );
        $this->app->instance(OrganizationAuthorizer::class, $authorizer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertActionHidden('grantMarketingConsent')
            ->assertActionHidden('revokeMarketingConsent');

        self::assertSame($organization->getKey(), $client->organization_id);
    }

    public function test_record_client_consent_rejects_unauthorized_actor(): void
    {
        [, , $client] = $this->fixture();
        $unauthorized = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(RecordClientConsent::class)->handle(
            actor: $unauthorized,
            client: $client,
            subject: ConsentSubject::Marketing,
            version: 'marketing-2026-01',
            granted: true,
            evidence: 'crm',
        );
    }

    public function test_record_client_consent_rejects_cross_organization_client(): void
    {
        [, $admin] = $this->fixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        $this->expectException(AuthorizationException::class);
        app(RecordClientConsent::class)->handle(
            actor: $admin,
            client: $foreignClient,
            subject: ConsentSubject::Marketing,
            version: 'marketing-2026-01',
            granted: true,
            evidence: 'crm',
        );
    }

    public function test_broadcast_eligibility_follows_the_latest_marketing_consent(): void
    {
        [, $admin, $client] = $this->fixture();
        $policy = app(BroadcastEligibilityPolicy::class);
        $channels = ['telegram'];

        self::assertSame('marketing_consent_missing', $policy->evaluate($client, $client->organization_id, $channels)['reason']);

        app(RecordClientConsent::class)->handle(
            actor: $admin,
            client: $client,
            subject: ConsentSubject::Marketing,
            version: 'marketing-2026-01',
            granted: true,
            evidence: 'telegram',
        );
        self::assertTrue($policy->evaluate($client, $client->organization_id, $channels)['eligible']);

        app(RecordClientConsent::class)->handle(
            actor: $admin,
            client: $client,
            subject: ConsentSubject::Marketing,
            version: 'marketing-2026-01',
            granted: false,
            evidence: 'telegram',
        );
        self::assertSame('marketing_suppressed', $policy->evaluate($client, $client->organization_id, $channels)['reason']);
    }

    /** @return array{0: Organization, 1: User, 2: Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $admin = User::factory()->forOrganization($organization)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $client = Client::factory()->forOrganization($organization)->create();
        ClientChannelIdentity::factory()->forClient($client)->create([
            'external_id' => '123456789',
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verified_at' => now(),
        ]);
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }
}
