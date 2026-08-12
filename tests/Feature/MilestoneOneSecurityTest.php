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
use App\Modules\Services\Domain\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    public function test_audit_recorder_redacts_sensitive_metadata(): void
    {
        $organization = Organization::factory()->create();
        $event = app(RecordAuditEvent::class)->handle(
            organization: $organization,
            actor: null,
            action: 'security.test',
            targetType: Service::class,
            targetId: '1',
            metadata: ['token' => 'secret-value', 'safe_id' => 'value'],
        );

        self::assertSame('[REDACTED]', $event->metadata['token']);
        self::assertSame('value', $event->metadata['safe_id']);
        self::assertStringNotContainsString('secret-value', json_encode($event->metadata, JSON_THROW_ON_ERROR));
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
}
