<?php

namespace Tests\Feature;

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
use Tests\TestCase;

class PrivilegedAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_requires_mfa_and_profile_management(): void
    {
        $panel = Filament::getPanel('admin');

        self::assertTrue($panel->hasMultiFactorAuthentication());
        self::assertTrue($panel->isMultiFactorAuthenticationRequired());
        self::assertTrue($panel->hasProfile());
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

        $this->actingAs($admin)->get('/admin')->assertRedirect();
        OrganizationMembership::query()->where('user_id', $admin->getKey())->update(['is_active' => false]);

        $this->get('/admin')->assertForbidden();
    }

    public function test_revoked_session_version_invalidates_an_existing_admin_session(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());

        $this->actingAs($admin)->get('/admin')->assertRedirect();
        $admin->increment('privileged_session_version');

        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
        self::assertGuest('web');
    }
}
