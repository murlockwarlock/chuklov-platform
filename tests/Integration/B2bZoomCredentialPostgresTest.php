<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\B2B\Application\SaveB2bZoomConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Throwable;

final class B2bZoomCredentialPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_concurrent_blank_preserve_and_explicit_replace_keep_the_newest_secret(): void
    {
        $this->requirePostgres();
        [$organization, $admin] = $this->fixture();
        $this->setOrganization($organization);
        app(SaveB2bZoomConfiguration::class)->handle(
            actor: $admin,
            accountId: 'account-s0',
            clientId: 'client-s0',
            clientSecret: 'secret-s0',
            hostUserId: 'host-s0',
            enabled: true,
        );

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::saveBlankInProcess($organization->getKey(), $admin->getKey()),
            static fn (): string => self::saveExplicitInProcess($organization->getKey(), $admin->getKey(), 'secret-s1'),
        ]);

        self::assertSame(['ok', 'ok'], $results);
        $credential = OrganizationCredential::query()->where('organization_id', $organization->getKey())->sole();
        self::assertSame('secret-s1', $credential->credentials['client_secret']);
        self::assertSame(3, AuditEvent::query()->where('organization_id', $organization->getKey())->where('action', 'organization.credential.replaced')->count());
    }

    public function test_concurrent_explicit_replacements_are_serialized_without_corrupting_the_credential(): void
    {
        $this->requirePostgres();
        [$organization, $admin] = $this->fixture();
        $this->setOrganization($organization);
        app(SaveB2bZoomConfiguration::class)->handle(
            actor: $admin,
            accountId: 'account-s0',
            clientId: 'client-s0',
            clientSecret: 'secret-s0',
            hostUserId: 'host-s0',
            enabled: true,
        );

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::saveExplicitInProcess($organization->getKey(), $admin->getKey(), 'secret-s2'),
            static fn (): string => self::saveExplicitInProcess($organization->getKey(), $admin->getKey(), 'secret-s3'),
        ]);

        self::assertSame(['ok', 'ok'], $results);
        $secret = OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->sole()
            ->credentials['client_secret'];
        self::assertContains($secret, ['secret-s2', 'secret-s3']);
    }

    private static function saveBlankInProcess(int $organizationId, int $adminId): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            app(SaveB2bZoomConfiguration::class)->handle(
                actor: User::query()->findOrFail($adminId),
                accountId: 'account-blank',
                clientId: 'client-blank',
                clientSecret: null,
                hostUserId: 'host-blank',
                enabled: true,
            );

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    private static function saveExplicitInProcess(int $organizationId, int $adminId, string $secret): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            app(SaveB2bZoomConfiguration::class)->handle(
                actor: User::query()->findOrFail($adminId),
                accountId: 'account-'.$secret,
                clientId: 'client-'.$secret,
                clientSecret: $secret,
                hostUserId: 'host-'.$secret,
                enabled: true,
            );

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();

        return [$organization, $admin];
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The Zoom credential concurrency tests require PostgreSQL.');
        }
    }
}
