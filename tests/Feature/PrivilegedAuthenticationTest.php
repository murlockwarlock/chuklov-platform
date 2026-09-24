<?php

namespace Tests\Feature;

use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login as AdminLogin;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Infrastructure\Filament\AuditedAppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class PrivilegedAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_offers_optional_mfa_and_profile_management(): void
    {
        $panel = Filament::getPanel('admin');

        self::assertTrue($panel->hasMultiFactorAuthentication());
        self::assertFalse($panel->isMultiFactorAuthenticationRequired());
        self::assertTrue($panel->hasProfile());
        self::assertSame(AdminLogin::class, $panel->getLoginRouteAction());
        self::assertSame(EditProfile::class, $panel->getProfilePage());
    }

    public function test_user_without_mfa_enters_crm_without_a_login_challenge(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(AdminLogin::class)
            ->fillForm(['email' => $admin->email, 'password' => 'password', 'remember' => false])
            ->call('authenticate')
            ->assertSet('userUndertakingMultiFactorAuthentication', null);

        self::assertAuthenticatedAs($admin);
        $this->get('/admin')->assertOk();
    }

    public function test_user_with_configured_mfa_enters_crm_without_a_login_challenge(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        $secret = app(AuditedAppAuthentication::class)->generateSecret();
        app(AuditedAppAuthentication::class)->saveSecret($admin, $secret);
        Filament::auth()->logout();

        $login = Livewire::test(AdminLogin::class)
            ->fillForm(['email' => $admin->email, 'password' => 'password', 'remember' => false])
            ->call('authenticate');

        self::assertNull($login->get('userUndertakingMultiFactorAuthentication'));
        self::assertAuthenticatedAs($admin);
        $this->get('/admin')->assertOk();
    }

    public function test_existing_profile_route_remains_available_for_mfa_management(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($admin)
            ->get(route('filament.admin.auth.profile'))
            ->assertOk();
    }

    public function test_recovery_code_provider_capability_consumes_and_audits_only_a_valid_code(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        $provider = app(AuditedAppAuthentication::class);
        $provider->saveSecret($admin, $provider->generateSecret());
        $recoveryCode = 'recovery-code-1';
        $provider->saveRecoveryCodes($admin, [$recoveryCode]);

        self::assertTrue($provider->verifyRecoveryCode($recoveryCode));

        self::assertSame([], $admin->fresh()->getAppAuthenticationRecoveryCodes());
        $audit = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('action', 'privileged.mfa.recovery_code.used')
            ->sole();
        self::assertNull($audit->actor_user_id);
        self::assertSame(User::class, $audit->target_type);
        self::assertSame((string) $admin->getKey(), $audit->target_id);
        self::assertSame([], $audit->metadata);
        self::assertStringNotContainsString($recoveryCode, (string) DB::table('audit_events')->where('id', $audit->getKey())->value('metadata'));

        self::assertFalse($provider->verifyRecoveryCode($recoveryCode));
        self::assertSame(1, AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('action', 'privileged.mfa.recovery_code.used')
            ->count());
    }

    public function test_mfa_secrets_are_encrypted_and_lifecycle_changes_are_audited(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);
        app(OrganizationContext::class)->set($organization);

        $provider = app(AuditedAppAuthentication::class);
        $provider->saveSecret($admin, 'encrypted-secret');
        $provider->saveRecoveryCodes($admin, ['recovery-code']);

        self::assertSame('encrypted-secret', $admin->fresh()->getAppAuthenticationSecret());
        self::assertTrue(Hash::check('recovery-code', $provider->getRecoveryCodes($admin->fresh())[0]));
        self::assertNotSame('encrypted-secret', DB::table('users')->where('id', $admin->getKey())->value('app_authentication_secret'));
        self::assertNotSame('recovery-code', DB::table('users')->where('id', $admin->getKey())->value('app_authentication_recovery_codes'));
        self::assertSame(2, AuditEvent::query()->where('organization_id', $organization->getKey())->whereIn('action', [
            'privileged.mfa.enabled',
            'privileged.mfa.recovery_codes.updated',
        ])->count());
    }

    public function test_revoking_privileged_sessions_invalidates_the_current_session_and_audits_the_operation(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $response = $this->actingAs($admin)->post(route('filament.admin.security.revoke-sessions'));

        $response->assertRedirect(route('filament.admin.auth.login'));
        self::assertGuest('web');
        self::assertSame(2, $admin->fresh()->privileged_session_version);
        self::assertSame(1, AuditEvent::query()->where('organization_id', $organization->getKey())->where('action', 'privileged.sessions.revoked')->count());
    }

    public function test_deactivated_membership_cannot_reuse_an_authenticated_admin_session(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());

        app(OrganizationContext::class)->set($organization);
        $this->actingAs($admin);
        app(AuditedAppAuthentication::class)->saveSecret($admin, 'configured-secret');
        $this->actingAs($admin)->get('/admin')->assertOk();
        OrganizationMembership::query()->where('user_id', $admin->getKey())->update(['is_active' => false]);

        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_revoked_session_version_invalidates_an_existing_admin_session(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());

        app(OrganizationContext::class)->set($organization);
        $this->actingAs($admin);
        app(AuditedAppAuthentication::class)->saveSecret($admin, 'configured-secret');
        $this->actingAs($admin)->get('/admin')->assertOk();
        $admin->increment('privileged_session_version');

        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
        self::assertGuest('web');
    }
}
