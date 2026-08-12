<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Application\CreateClient;
use App\Modules\Identity\Application\RecordClientConsent;
use App\Modules\Identity\Application\RegisterClientChannelIdentity;
use App\Modules\Identity\Domain\Enums\ConsentSubject;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Organizations\Application\GetOrganizationSetting;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationFeatureFlag;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Security\Application\ReplaceOrganizationCredential;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Security\Infrastructure\Logging\RedactSensitiveLogTap;
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Tests\TestCase;

class MilestoneOneSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_members_cannot_enter_the_server_resolved_organization(): void
    {
        $organization = Organization::factory()->create();
        $nonMember = User::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->id);

        $this->actingAs($nonMember)->get('/')->assertForbidden();
    }

    public function test_settings_are_typed_isolated_and_audited(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $otherOrganization = Organization::factory()->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();

        $setting = app(SetOrganizationSetting::class)->handle(
            actor: $admin,
            key: OrganizationSettingKey::DefaultTimezone,
            value: 'Asia/Almaty',
        );

        self::assertSame('Asia/Almaty', $setting->typedValue());
        self::assertTrue(Gate::forUser($admin)->allows('update', $setting));
        self::assertFalse(Gate::forUser($otherAdmin)->allows('update', $setting));
        self::assertSame(1, AuditEvent::query()->where('action', 'organization.setting.updated')->count());

        app(OrganizationContext::class)->set($otherOrganization);

        self::assertNull(app(GetOrganizationSetting::class)->handle(
            actor: $otherAdmin,
            key: OrganizationSettingKey::DefaultTimezone,
        ));
        self::assertSame($organization->id, $setting->organization_id);
    }

    public function test_feature_entitlement_is_required_by_the_application_and_policy(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();

        self::assertFalse(Gate::forUser($admin)->allows('view', $client));

        $this->expectException(AuthorizationException::class);

        app(CreateClient::class)->handle($admin, 'Blocked Client');
    }

    public function test_client_access_is_organization_scoped_and_feature_gated(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $otherOrganization = Organization::factory()->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();
        OrganizationMembership::factory()->forOrganization($otherOrganization)->forUser($admin)->create();
        $this->enableClientRecords($organization, $admin);
        OrganizationFeatureFlag::factory()->forOrganization($otherOrganization)->create();

        $client = app(CreateClient::class)->handle($admin, 'Organization A Client');
        app(OrganizationContext::class)->set($otherOrganization);
        $otherClient = app(CreateClient::class)->handle($otherAdmin, 'Organization B Client');
        app(OrganizationContext::class)->set($organization);

        self::assertTrue(Gate::forUser($admin)->allows('view', $client));
        self::assertFalse(Gate::forUser($admin)->allows('view', $otherClient));
        self::assertFalse(Gate::forUser($otherAdmin)->allows('update', $client));
        self::assertSame($organization->id, $client->organization_id);
    }

    public function test_client_identities_are_unverified_separate_and_cannot_cross_link(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $otherOrganization = Organization::factory()->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();
        $this->enableClientRecords($organization, $admin);
        OrganizationFeatureFlag::factory()->forOrganization($otherOrganization)->create();

        $client = app(CreateClient::class)->handle($admin, 'Organization A Client');
        app(RegisterClientChannelIdentity::class)->handle($admin, $client, 'telegram', 'same-external-id');
        app(OrganizationContext::class)->set($otherOrganization);
        $otherClient = app(CreateClient::class)->handle($otherAdmin, 'Organization B Client');

        app(RegisterClientChannelIdentity::class)->handle($otherAdmin, $otherClient, 'telegram', 'same-external-id');
        app(OrganizationContext::class)->set($organization);

        self::assertSame(2, ClientChannelIdentity::query()->where('external_id', 'same-external-id')->count());
        self::assertSame(0, ClientChannelIdentity::query()->where('verification_status', 'verified')->count());

        $this->expectException(AuthorizationException::class);

        app(RegisterClientChannelIdentity::class)->handle($admin, $otherClient, 'telegram', 'forbidden-link');
    }

    public function test_consent_subjects_preserve_required_and_optional_distinction(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->enableClientRecords($organization, $admin);
        $client = app(CreateClient::class)->handle($admin, 'Consent Client');

        $privacy = app(RecordClientConsent::class)->handle(
            actor: $admin,
            client: $client,
            subject: ConsentSubject::Privacy,
            version: 'privacy-2026-01',
            granted: true,
            evidence: 'crm',
        );
        $marketing = app(RecordClientConsent::class)->handle(
            actor: $admin,
            client: $client,
            subject: ConsentSubject::Marketing,
            version: 'marketing-2026-01',
            granted: false,
            evidence: 'crm',
        );

        self::assertTrue($privacy->is_required);
        self::assertFalse($marketing->is_required);
        self::assertFalse($marketing->granted);
        self::assertSame(2, ClientConsent::query()->where('client_id', $client->id)->count());
    }

    public function test_credentials_are_encrypted_masked_rotatable_and_authorized(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $secret = 'organization-secret-value';

        $credential = app(ReplaceOrganizationCredential::class)->handle(
            actor: $admin,
            provider: 'telegram',
            credentialName: 'bot',
            credentials: ['token' => $secret],
        );

        $raw = DB::table('organization_credentials')->where('id', $credential->id)->value('credentials');

        self::assertIsString($raw);
        self::assertStringNotContainsString($secret, $raw);
        self::assertSame($secret, $credential->credentials['token']);
        self::assertArrayNotHasKey('credentials', $credential->toArray());
        self::assertArrayNotHasKey('token', $credential->masked());
        self::assertTrue(Gate::forUser($admin)->allows('view', $credential));
        self::assertFalse(Gate::forUser($staff)->allows('view', $credential));
        self::assertSame(1, AuditEvent::query()->where('action', 'organization.credential.replaced')->count());

        $event = AuditEvent::query()->where('action', 'organization.credential.replaced')->sole();
        self::assertStringNotContainsString($secret, json_encode($event->metadata, JSON_THROW_ON_ERROR));

        $this->expectException(AuthorizationException::class);

        app(ReplaceOrganizationCredential::class)->handle(
            actor: $staff,
            provider: 'telegram',
            credentialName: 'bot',
            credentials: ['token' => 'another-secret'],
        );
    }

    public function test_audit_recorder_drops_undeclared_metadata(): void
    {
        $organization = Organization::factory()->create();
        $event = app(RecordAuditEvent::class)->handle(
            organization: $organization,
            actor: null,
            action: 'security.test',
            targetType: Service::class,
            targetId: '1',
            metadata: [
                'api_key' => 'api-secret-value',
                'access_key' => 'access-secret-value',
                'safe_label' => 'secret-value',
            ],
        );

        self::assertSame([], $event->metadata);
        self::assertStringNotContainsString('secret-value', json_encode($event->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_audit_metadata_only_persists_declared_fields_for_a_known_action(): void
    {
        $organization = Organization::factory()->create();
        $event = app(RecordAuditEvent::class)->handle(
            organization: $organization,
            actor: null,
            action: 'client.created',
            targetType: Client::class,
            targetId: '1',
            metadata: [
                'source' => 'application',
                'api_key' => 'api-secret-value',
                'access_key' => 'access-secret-value',
                'note' => 'secret-value',
            ],
        );

        self::assertSame(['source' => 'application'], $event->metadata);
        self::assertStringNotContainsString('secret-value', json_encode($event->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_configured_output_channels_all_use_the_redaction_tap(): void
    {
        $channels = config('logging.channels');

        foreach (['single', 'daily', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog'] as $channel) {
            self::assertIsArray($channels[$channel]['tap'] ?? null);
            self::assertContains(RedactSensitiveLogTap::class, $channels[$channel]['tap']);
        }
    }

    public function test_credential_replacement_rolls_back_when_audit_persistence_fails(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->failAuditPersistence();

        try {
            app(ReplaceOrganizationCredential::class)->handle(
                actor: $admin,
                provider: 'telegram',
                credentialName: 'bot',
                credentials: ['token' => 'credential-secret'],
            );
            self::fail('The audit failure should abort credential replacement.');
        } catch (RuntimeException) {
            self::assertSame(0, OrganizationCredential::query()
                ->where('organization_id', $organization->id)
                ->count());
        }
    }

    public function test_client_creation_rolls_back_when_audit_persistence_fails(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->enableClientRecords($organization, $admin);
        $this->failAuditPersistence();

        try {
            app(CreateClient::class)->handle($admin, 'Atomicity Client');
            self::fail('The audit failure should abort client creation.');
        } catch (RuntimeException) {
            self::assertSame(0, Client::query()
                ->where('organization_id', $organization->id)
                ->count());
        }
    }

    public function test_settings_and_feature_changes_roll_back_when_audit_persistence_fails(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->failAuditPersistence();

        try {
            app(SetOrganizationSetting::class)->handle(
                actor: $admin,
                key: OrganizationSettingKey::DefaultTimezone,
                value: 'Asia/Almaty',
            );
            self::fail('The audit failure should abort the setting change.');
        } catch (RuntimeException) {
            self::assertSame(0, $organization->settings()->count());
        }

        try {
            app(SetOrganizationFeatureFlag::class)->handle($admin, OrganizationFeature::ServiceCatalog, true);
            self::fail('The audit failure should abort the feature change.');
        } catch (RuntimeException) {
            self::assertSame(0, $organization->featureFlags()->count());
        }
    }

    public function test_client_identity_and_consent_changes_roll_back_when_audit_persistence_fails(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $this->enableClientRecords($organization, $admin);
        $client = app(CreateClient::class)->handle($admin, 'Atomicity Client');
        $this->failAuditPersistence();

        try {
            app(RegisterClientChannelIdentity::class)->handle($admin, $client, 'telegram', 'atomicity-id');
            self::fail('The audit failure should abort the channel identity change.');
        } catch (RuntimeException) {
            self::assertSame(0, $client->channelIdentities()->count());
        }

        try {
            app(RecordClientConsent::class)->handle(
                actor: $admin,
                client: $client,
                subject: ConsentSubject::Privacy,
                version: 'privacy-2026-01',
                granted: true,
                evidence: 'crm',
            );
            self::fail('The audit failure should abort the consent change.');
        } catch (RuntimeException) {
            self::assertSame(0, $client->consents()->count());
        }
    }

    public function test_organization_ownership_fields_are_not_mass_assignable(): void
    {
        $client = new Client;
        $credential = new OrganizationCredential;

        $client->fill(['organization_id' => 9001, 'full_name' => 'Safe']);
        $credential->fill(['organization_id' => 9001, 'credentials' => ['token' => 'secret']]);

        self::assertSame('Safe', $client->full_name);
        self::assertArrayNotHasKey('organization_id', $client->getAttributes());
        self::assertArrayNotHasKey('credentials', $credential->getAttributes());
    }

    public function test_security_sensitive_domain_state_is_not_mass_assignable(): void
    {
        $membership = new OrganizationMembership;
        $identity = new ClientChannelIdentity;
        $consent = new ClientConsent;
        $credential = new OrganizationCredential;

        self::assertFalse($membership->isFillable('role'));
        self::assertFalse($membership->isFillable('is_active'));
        self::assertFalse($identity->isFillable('verification_status'));
        self::assertFalse($identity->isFillable('verification_method'));
        self::assertFalse($identity->isFillable('verified_at'));
        self::assertFalse($consent->isFillable('is_required'));
        self::assertFalse($consent->isFillable('granted'));
        self::assertFalse($consent->isFillable('recorded_at'));
        self::assertFalse($credential->isFillable('status'));
        self::assertFalse($credential->isFillable('last_rotated_at'));
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function enableClientRecords(Organization $organization, User $admin): void
    {
        app(SetOrganizationFeatureFlag::class)->handle($admin, OrganizationFeature::ClientRecords, true);
        app(OrganizationContext::class)->set($organization);
    }

    private function failAuditPersistence(): void
    {
        $audit = $this->createMock(RecordAuditEvent::class);
        $audit->method('handle')->willThrowException(new RuntimeException('audit persistence failed'));
        $this->app->instance(RecordAuditEvent::class, $audit);
    }
}
