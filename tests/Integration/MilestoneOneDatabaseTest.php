<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use App\Modules\Security\Application\ReplaceOrganizationCredential;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MilestoneOneDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_composite_foreign_key_rejects_cross_organization_identity_links(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();

        $identity = new ClientChannelIdentity;
        $identity->forceFill([
            'organization_id' => $otherOrganization->id,
            'client_id' => $client->id,
            'channel' => 'telegram',
            'external_id' => 'cross-org',
            'verification_status' => 'unverified',
        ]);

        $this->expectException(QueryException::class);

        $identity->save();
    }

    public function test_same_credential_name_is_scoped_by_organization(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();

        app(OrganizationContext::class)->set($organization);
        $first = app(ReplaceOrganizationCredential::class)->handle(
            actor: $admin,
            provider: 'calendar',
            credentialName: 'default',
            credentials: ['key' => 'first-secret'],
        );
        app(OrganizationContext::class)->set($otherOrganization);
        $second = app(ReplaceOrganizationCredential::class)->handle(
            actor: $otherAdmin,
            provider: 'calendar',
            credentialName: 'default',
            credentials: ['key' => 'second-secret'],
        );

        self::assertNotSame($first->id, $second->id);
        self::assertSame(2, DB::table('organization_credentials')
            ->where('provider', 'calendar')
            ->where('credential_name', 'default')
            ->count());
        self::assertStringNotContainsString('first-secret', (string) DB::table('organization_credentials')->where('id', $first->id)->value('credentials'));
        self::assertStringNotContainsString('second-secret', (string) DB::table('organization_credentials')->where('id', $second->id)->value('credentials'));
    }

    public function test_populated_legacy_users_are_backfilled_without_contracting_legacy_columns(): void
    {
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $administrator = User::factory()->create();
        $staff = User::factory()->create();
        $multiMembershipUser = User::factory()->create();

        OrganizationMembership::factory()
            ->forOrganization($otherOrganization)
            ->forUser($multiMembershipUser)
            ->create(['role' => OrganizationRole::Owner->value]);

        DB::table('users')->whereIn('id', [$administrator->id, $staff->id, $multiMembershipUser->id])->update([
            'organization_id' => $organization->id,
        ]);
        DB::table('users')->where('id', $administrator->id)->update(['is_admin' => true]);
        DB::table('users')->where('id', $staff->id)->update(['is_admin' => false]);
        DB::table('users')->where('id', $multiMembershipUser->id)->update(['is_admin' => false]);

        $migration = require database_path('migrations/2026_08_12_114054_backfill_organization_memberships.php');
        $migration->up();
        $migration->up();

        self::assertTrue(Schema::hasColumn('users', 'organization_id'));
        self::assertTrue(Schema::hasColumn('users', 'is_admin'));
        self::assertSame('administrator', DB::table('organization_memberships')
            ->where('organization_id', $organization->id)
            ->where('user_id', $administrator->id)
            ->value('role'));
        self::assertSame('staff', DB::table('organization_memberships')
            ->where('organization_id', $organization->id)
            ->where('user_id', $staff->id)
            ->value('role'));
        self::assertSame(2, OrganizationMembership::query()
            ->where('user_id', $multiMembershipUser->id)
            ->count());
        self::assertSame(4, OrganizationMembership::query()->count());
    }
}
