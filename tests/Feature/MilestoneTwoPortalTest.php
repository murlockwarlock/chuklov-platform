<?php

namespace Tests\Feature;

use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\Channels\Infrastructure\Telegram\TelegramInitDataVerifier;
use App\Modules\ClientPortal\Domain\Models\ClientOnboarding;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Application\AuthenticateClientWithVerifiedChannel;
use App\Modules\Identity\Application\CreatePlatformLegalDocumentDraft;
use App\Modules\Identity\Application\PublishLegalDocument;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientAcquisitionRegistration;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Referrals\Application\EnsureReferralIdentity;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use App\Modules\Referrals\Domain\Models\ReferralRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Tests\Support\TelegramInitData;
use Tests\TestCase;

class MilestoneTwoPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_portal_path_is_shared_and_onboarding_requires_a_client_session(): void
    {
        $organization = $this->organizationWithClientRecords();

        $this->get(route('portal.home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Entry')
                ->where('portal.authenticated', false));

        $this->get(route('portal.services.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Services/Index')
                ->where('portal.authenticated', false));

        $this->get(route('portal.onboarding'))->assertUnauthorized();
        self::assertSame($organization->id, (int) config('tenancy.default_organization_id'));
    }

    public function test_portal_urls_preserve_the_forwarded_https_scheme(): void
    {
        $this->organizationWithClientRecords();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'crm.psysoldatov.ru',
                'X-Forwarded-Port' => '443',
            ])
            ->get(route('portal.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('portal.localeUrl', 'https://crm.psysoldatov.ru/portal/locale')
                ->where('auth.emailRequestUrl', 'https://crm.psysoldatov.ru/portal/auth/email/request'));
    }

    public function test_valid_telegram_auth_creates_a_verified_identity_and_session(): void
    {
        $this->organizationWithClientRecords();
        $this->useTelegramToken();

        $response = $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(100001, now()->timestamp, firstName: 'New', username: 'new_client'),
            'organization_id' => 999999,
            'client_id' => 999999,
        ]);

        $response->assertRedirect(route('portal.home'));
        $client = Client::query()->sole();
        $identity = ClientChannelIdentity::query()->sole();

        self::assertSame('New Client', $client->full_name);
        self::assertSame(ChannelIdentityStatus::Verified, $identity->verification_status);
        self::assertSame('100001', $identity->external_id);
        self::assertSame('new_client', $identity->external_username);
        $response->assertSessionHas('client_portal.client_id', $client->id);

        $this->get(route('portal.home'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Home')
                ->where('portal.clientName', 'New Client'));
    }

    public function test_telegram_mini_app_acquisition_registration_survives_a_finalization_boundary_and_returning_retry(): void
    {
        $organization = $this->organizationWithClientRecords();
        app(OrganizationContext::class)->set($organization);
        $this->useTelegramToken();
        $referrer = Client::factory()->forOrganization($organization)->create();
        $identity = app(EnsureReferralIdentity::class)->handle($referrer);
        $sessionId = 'telegram-mini-app-acquisition-session';
        app(CapturePreAuthAttribution::class)->handle($sessionId, ['referral_code' => $identity->public_code]);

        $verifiedIdentity = app(TelegramInitDataVerifier::class)->handle(
            TelegramInitData::make(100010, now()->timestamp, firstName: 'Mini App'),
        );
        $client = app(AuthenticateClientWithVerifiedChannel::class)->handle(
            verifiedIdentity: $verifiedIdentity,
            acquisitionSessionId: $sessionId,
        );
        $registration = ClientAcquisitionRegistration::query()->where('client_id', $client->getKey())->sole();

        self::assertNull($registration->finalized_at);
        self::assertSame(2, Client::query()->count());

        $retryClient = Client::query()->whereKey($client->getKey())->firstOrFail();
        app(FinalizeClientAcquisition::class)->handle($retryClient, $sessionId);
        app(FinalizeClientAcquisition::class)->handle($retryClient, $sessionId);

        self::assertNotNull($registration->refresh()->finalized_at);
        self::assertSame(1, ReferralRelationship::query()->where('referred_client_id', $client->getKey())->count());

        $returningSessionId = 'telegram-mini-app-returning-session';
        app(CapturePreAuthAttribution::class)->handle($returningSessionId, ['referral_code' => $identity->public_code]);
        $returningIdentity = app(TelegramInitDataVerifier::class)->handle(
            TelegramInitData::make(100010, now()->addSecond()->timestamp, firstName: 'Changed'),
        );
        $returningClient = app(AuthenticateClientWithVerifiedChannel::class)->handle(
            verifiedIdentity: $returningIdentity,
            acquisitionSessionId: $returningSessionId,
        );

        self::assertSame($client->getKey(), $returningClient->getKey());
        self::assertSame(1, ClientAcquisitionRegistration::query()->where('client_id', $client->getKey())->count());
        self::assertSame(1, ReferralRelationship::query()->where('referred_client_id', $client->getKey())->count());
    }

    public function test_telegram_auth_keeps_client_records_feature_as_a_server_gate(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        $this->useTelegramToken();

        $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(100000, now()->timestamp),
        ])
            ->assertRedirect(route('portal.home'))
            ->assertSessionHas('telegram_auth_error');

        self::assertSame(0, Client::query()->count());
        self::assertFalse($organization->featureFlags()->where('feature_key', OrganizationFeature::ClientRecords->value)->exists());

        $this->get(route('portal.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.telegramAuthError', 'Не удалось войти через Telegram. Закройте приложение и откройте его снова.'));
    }

    public function test_invalid_signature_and_stale_or_replayed_auth_are_rejected(): void
    {
        $this->organizationWithClientRecords();
        $this->useTelegramToken();
        $payload = TelegramInitData::make(100002, now()->timestamp);

        $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(100002, now()->timestamp, token: 'wrong-token'),
        ])->assertRedirect(route('portal.home'));

        $this->post(route('portal.telegram.auth'), ['initData' => $payload])->assertRedirect();
        $this->post(route('portal.telegram.auth'), ['initData' => $payload])->assertRedirect(route('portal.home'));
        $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(100003, now()->timestamp - 901),
        ])->assertRedirect(route('portal.home'));
    }

    public function test_valid_telegram_evidence_verifies_existing_identity_without_overwriting_profile_values(): void
    {
        $organization = $this->organizationWithClientRecords();
        $this->useTelegramToken();
        $client = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Verified CRM Name',
            'email' => 'known@example.test',
            'phone' => '+10000000000',
        ]);
        $identity = ClientChannelIdentity::factory()->forClient($client)->create([
            'external_id' => '100004',
        ]);

        $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(100004, now()->timestamp, firstName: 'Different'),
        ])->assertRedirect(route('portal.home'));

        $identity->refresh();
        $client->refresh();
        self::assertSame(ChannelIdentityStatus::Verified, $identity->verification_status);
        self::assertSame('Verified CRM Name', $client->full_name);
        self::assertSame('known@example.test', $client->email);
        self::assertSame('+10000000000', $client->phone);
    }

    public function test_frontend_identity_and_organization_input_cannot_impersonate_another_client(): void
    {
        $organization = $this->organizationWithClientRecords();
        $otherOrganization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Signed Client']);
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create(['full_name' => 'Other Client']);
        ClientChannelIdentity::factory()->forClient($client)->create([
            'external_id' => '100005',
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verification_method' => 'authenticated_channel_flow',
            'verified_at' => now(),
        ]);
        ClientChannelIdentity::factory()->forClient($otherClient)->create([
            'external_id' => '100006',
            'verification_status' => ChannelIdentityStatus::Verified->value,
            'verification_method' => 'authenticated_channel_flow',
            'verified_at' => now(),
        ]);
        $this->useTelegramToken();

        $response = $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(100005, now()->timestamp),
            'telegram_user_id' => 100006,
            'client_id' => $otherClient->id,
            'organization_id' => $otherOrganization->id,
        ])->assertRedirect(route('portal.home'));

        $response->assertSessionHas('client_portal.client_id', $client->id);
        self::assertNotSame($otherClient->id, (int) session('client_portal.client_id'));
    }

    public function test_same_external_id_in_another_organization_is_not_linked(): void
    {
        $organization = $this->organizationWithClientRecords();
        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        ClientChannelIdentity::factory()->forClient($otherClient)->create(['external_id' => '100007']);
        $this->useTelegramToken();

        $this->post(route('portal.telegram.auth'), [
            'initData' => TelegramInitData::make(100007, now()->timestamp),
        ])->assertRedirect(route('portal.home'));

        self::assertSame(2, ClientChannelIdentity::query()->where('external_id', '100007')->count());
        self::assertSame(2, Client::query()->count());
        self::assertSame($organization->id, (int) config('tenancy.default_organization_id'));
    }

    public function test_profile_is_progressive_and_the_legacy_onboarding_route_is_not_a_destination(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Known Client',
            'email' => 'known@example.test',
            'phone' => null,
            'lead_source' => null,
        ]);
        ClientChannelIdentity::factory()->forClient($client)->create([
            'external_id' => '100008',
            'verification_status' => ChannelIdentityStatus::Verified->value,
        ]);
        ClientOnboarding::factory()->forClient($client)->create();
        $this->withSession(['client_portal.client_id' => $client->id]);

        $this->get(route('portal.onboarding'))
            ->assertRedirect(route('portal.profile'));

        $this->get(route('portal.profile'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Profile')
                ->where('profile.fullName', 'Known Client')
                ->where('profile.email', 'known@example.test')
                ->missing('lead_source')
                ->missing('referral_code'));

        $this->post(route('portal.profile.update'), [
            'full_name' => 'Changed Without Confirmation',
            'email' => 'known@example.test',
            'phone' => null,
        ])->assertRedirect(route('portal.profile'));
        self::assertSame('Changed Without Confirmation', $client->refresh()->full_name);
    }

    public function test_profile_records_consent_against_the_presented_published_version(): void
    {
        $organization = $this->organizationWithClientRecords();
        $client = Client::factory()->forOrganization($organization)->create();
        $document = app(PublishLegalDocument::class)->handle(app(CreatePlatformLegalDocumentDraft::class)->handle(
            organization: $organization,
            documentType: 'privacy',
            purpose: 'privacy_consent',
            locale: 'en',
            version: '2026-08-12-v1',
            content: 'Configured privacy text.',
            isRequired: true,
        ));
        $this->withSession(['client_portal.client_id' => $client->id]);

        $this->post(route('portal.profile.consents'), [
            'consents' => [[
                'legal_document_id' => $document->id,
                'granted' => true,
            ]],
        ])->assertRedirect(route('portal.profile'));

        $consent = ClientConsent::query()->sole();
        self::assertSame($document->id, $consent->legal_document_id);
        self::assertSame($document->version, $consent->version);
        self::assertNotNull($consent->legalDocument);
        self::assertNull(ClientOnboarding::query()->where('client_id', $client->id)->whereNotNull('completed_at')->first());
    }

    public function test_portal_and_conversation_security_state_is_not_mass_assignable(): void
    {
        $onboarding = new ClientOnboarding;
        $message = new ConversationMessage;

        $onboarding->fill([
            'organization_id' => 9001,
            'client_id' => 9001,
            'current_stage' => 'goals',
            'completed_at' => now(),
            'flow_version' => 'm2-v1',
        ]);
        $message->fill([
            'organization_id' => 9001,
            'conversation_id' => 9001,
            'direction' => 'outbound',
            'author_type' => 'ai',
        ]);

        self::assertArrayNotHasKey('organization_id', $onboarding->getAttributes());
        self::assertArrayNotHasKey('client_id', $onboarding->getAttributes());
        self::assertArrayNotHasKey('current_stage', $onboarding->getAttributes());
        self::assertArrayNotHasKey('completed_at', $onboarding->getAttributes());
        self::assertArrayNotHasKey('organization_id', $message->getAttributes());
        self::assertArrayNotHasKey('direction', $message->getAttributes());
        self::assertArrayNotHasKey('author_type', $message->getAttributes());
    }

    public function test_identity_audit_metadata_does_not_persist_init_data_or_profile_values(): void
    {
        $organization = $this->organizationWithClientRecords();
        $this->useTelegramToken();
        $payload = TelegramInitData::make(100009, now()->timestamp);

        $this->post(route('portal.telegram.auth'), ['initData' => $payload])->assertRedirect();

        $metadata = DB::table('audit_events')->pluck('metadata')->implode('|');
        self::assertStringNotContainsString($payload, $metadata);
        self::assertStringNotContainsString('hash=', $metadata);
        self::assertSame($organization->id, (int) config('tenancy.default_organization_id'));
    }

    private function organizationWithClientRecords(): Organization
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->id);

        return $organization;
    }

    private function useTelegramToken(): void
    {
        config()->set('nutgram.token', FakeNutgram::TOKEN);
        app()->forgetInstance(Nutgram::class);
    }
}
