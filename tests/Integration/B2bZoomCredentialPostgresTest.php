<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\B2B\Application\SaveB2bZoomConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Closure;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\InvokedProcess;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\SerializableClosure\SerializableClosure;
use Symfony\Component\Process\Process as SymfonyProcess;
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

        $credential = $this->zoomCredential($organization);
        $results = $this->runConcurrentWithCredentialLock($credential, [
            static fn (): string => self::saveBlankInProcess($organization->getKey(), $admin->getKey(), 'zoom-existing-blank'),
            static fn (): string => self::saveExplicitInProcess($organization->getKey(), $admin->getKey(), 'secret-s1', 'zoom-existing-explicit'),
        ], ['zoom-existing-blank', 'zoom-existing-explicit']);

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

        $credential = $this->zoomCredential($organization);
        $results = $this->runConcurrentWithCredentialLock($credential, [
            static fn (): string => self::saveExplicitInProcess($organization->getKey(), $admin->getKey(), 'secret-s2', 'zoom-existing-explicit-two'),
            static fn (): string => self::saveExplicitInProcess($organization->getKey(), $admin->getKey(), 'secret-s3', 'zoom-existing-explicit-three'),
        ], ['zoom-existing-explicit-two', 'zoom-existing-explicit-three']);

        self::assertSame(['ok', 'ok'], $results);
        $secret = OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->sole()
            ->credentials['client_secret'];
        self::assertContains($secret, ['secret-s2', 'secret-s3']);
    }

    public function test_concurrent_first_credential_creation_is_serialized_without_unique_violation(): void
    {
        $this->requirePostgres();
        [$organization, $admin] = $this->fixture();
        $this->setOrganization($organization);

        $results = $this->runConcurrentWithOrganizationLock($organization, [
            static fn (): string => self::saveExplicitInProcess($organization->getKey(), $admin->getKey(), 'secret-first-one', 'zoom-first-one'),
            static fn (): string => self::saveExplicitInProcess($organization->getKey(), $admin->getKey(), 'secret-first-two', 'zoom-first-two'),
        ], ['zoom-first-one', 'zoom-first-two']);

        self::assertSame(['ok', 'ok'], $results);
        $credential = OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->sole();
        self::assertContains($credential->credentials['client_secret'], ['secret-first-one', 'secret-first-two']);
        self::assertSame(2, AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('action', 'organization.credential.replaced')
            ->count());
        $latestAudit = AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('action', 'organization.credential.replaced')
            ->latest('id')
            ->firstOrFail();
        self::assertSame($latestAudit->metadata['new_revision_id'], $credential->revision_id);
    }

    public function test_concurrent_first_credential_creation_and_blank_save_have_defined_serialized_outcomes(): void
    {
        $this->requirePostgres();
        [$organization, $admin] = $this->fixture();
        $this->setOrganization($organization);

        $results = $this->runConcurrentWithOrganizationLock($organization, [
            static fn (): string => self::saveBlankInProcess($organization->getKey(), $admin->getKey(), 'zoom-first-blank'),
            static fn (): string => self::saveExplicitInProcess($organization->getKey(), $admin->getKey(), 'secret-first-explicit', 'zoom-first-explicit'),
        ], ['zoom-first-blank', 'zoom-first-explicit']);

        self::assertContains('ok', $results);
        self::assertNotContains('error', $results);
        self::assertContains($results[0], ['ok', 'validation']);
        $credential = OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->sole();
        self::assertSame('secret-first-explicit', $credential->credentials['client_secret']);
        self::assertContains(AuditEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('action', 'organization.credential.replaced')
            ->count(), [1, 2]);
    }

    private static function saveBlankInProcess(int $organizationId, int $adminId, string $readyToken): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            self::setProcessApplicationName($readyToken);
            self::signalReady($readyToken);
            app(SaveB2bZoomConfiguration::class)->handle(
                actor: User::query()->findOrFail($adminId),
                accountId: 'account-blank',
                clientId: 'client-blank',
                clientSecret: null,
                hostUserId: 'host-blank',
                enabled: true,
            );

            return 'ok';
        } catch (ValidationException) {
            return 'validation';
        } catch (Throwable) {
            return 'error';
        }
    }

    private static function saveExplicitInProcess(int $organizationId, int $adminId, string $secret, string $readyToken): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            self::setProcessApplicationName($readyToken);
            self::signalReady($readyToken);
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

    private static function signalReady(string $readyToken): void
    {
        fwrite(STDERR, $readyToken.PHP_EOL);
        fflush(STDERR);
    }

    private static function setProcessApplicationName(string $readyToken): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "select set_config('application_name', ?, false)",
            ['chuklov-m11d-zoom-'.$readyToken],
        );
    }

    /**
     * @param  list<Closure>  $tasks
     * @param  list<string>  $readyTokens
     * @return array<int, string>
     */
    private function runConcurrentWithOrganizationLock(Organization $organization, array $tasks, array $readyTokens): array
    {
        return $this->runConcurrentWithLock(
            function () use ($organization): void {
                Organization::query()
                    ->whereKey($organization->getKey())
                    ->lock('for no key update')
                    ->firstOrFail();
            },
            $tasks,
            $readyTokens,
        );
    }

    /**
     * @param  list<Closure>  $tasks
     * @param  list<string>  $readyTokens
     * @return array<int, string>
     */
    private function runConcurrentWithCredentialLock(OrganizationCredential $credential, array $tasks, array $readyTokens): array
    {
        return $this->runConcurrentWithLock(
            function () use ($credential): void {
                OrganizationCredential::query()
                    ->where('organization_id', $credential->getAttribute('organization_id'))
                    ->where('provider', $credential->getAttribute('provider'))
                    ->where('credential_name', $credential->getAttribute('credential_name'))
                    ->lockForUpdate()
                    ->firstOrFail();
            },
            $tasks,
            $readyTokens,
        );
    }

    /**
     * @param  list<Closure>  $tasks
     * @param  list<string>  $readyTokens
     * @return array<int, string>
     */
    private function runConcurrentWithLock(Closure $lock, array $tasks, array $readyTokens): array
    {
        $connection = DB::connection();
        $connection->beginTransaction();
        $lock();

        $pool = null;

        try {
            $command = ConsoleApplication::formatCommandString('invoke-serialized-closure');
            $pool = app(ProcessFactory::class)->pool(function (Pool $pool) use ($tasks, $command): void {
                foreach ($tasks as $key => $task) {
                    $pool->as((string) $key)
                        ->path(base_path())
                        ->env([
                            'LARAVEL_INVOKABLE_CLOSURE' => base64_encode(serialize(new SerializableClosure($task))),
                        ])
                        ->timeout(30)
                        ->command($command);
                }
            })->start();
            $processes = $pool->running();
            self::assertCount(count($tasks), $processes);

            foreach ($processes as $index => $process) {
                self::assertInstanceOf(InvokedProcess::class, $process);
                $readyToken = $readyTokens[$index];
                $readyOutput = '';
                $process->waitUntil(function (string $type, string $buffer) use (&$readyOutput, $readyToken): bool {
                    if ($type !== SymfonyProcess::ERR) {
                        return false;
                    }

                    $readyOutput .= $buffer;

                    return str_contains($readyOutput, $readyToken.PHP_EOL);
                });
                self::assertTrue($process->running());
            }

            $connection->commit();

            return $pool->wait()->collect()->map(function ($result): string {
                self::assertTrue($result->successful(), $result->errorOutput());
                $payload = json_decode($result->output(), true);
                self::assertIsArray($payload);
                self::assertTrue($payload['successful'] ?? false, $result->output());
                self::assertIsString($payload['result'] ?? null);
                $value = unserialize($payload['result']);
                self::assertIsString($value);

                return $value;
            })->values()->all();
        } catch (ProcessTimedOutException $exception) {
            $this->writePostgresLockDiagnostics();
            throw $exception;
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            if ($pool !== null && $pool->running()->isNotEmpty()) {
                $pool->stop(1);
            }
        }
    }

    private function writePostgresLockDiagnostics(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        try {
            $activities = DB::select(
                <<<'SQL'
SELECT pid, application_name, state, wait_event_type, wait_event,
       pg_blocking_pids(pid) AS blocking_pids
FROM pg_stat_activity
WHERE application_name LIKE ?
ORDER BY pid
SQL,
                ['chuklov-m11d-zoom-%'],
            );
            $locks = DB::select(
                <<<'SQL'
SELECT locks.pid, locks.locktype,
       CASE WHEN locks.relation IS NULL THEN NULL ELSE locks.relation::regclass::text END AS relation_name,
       locks.mode, locks.granted
FROM pg_locks AS locks
JOIN pg_stat_activity AS activity ON activity.pid = locks.pid
WHERE activity.application_name LIKE ?
ORDER BY locks.pid, locks.granted, locks.locktype, relation_name, locks.mode
SQL,
                ['chuklov-m11d-zoom-%'],
            );

            fwrite(STDERR, 'PostgreSQL lock diagnostics: '.json_encode([
                'activities' => array_map(static function (object $row): array {
                    $values = get_object_vars($row);

                    return [
                        'pid' => (int) ($values['pid'] ?? 0),
                        'application_name' => (string) ($values['application_name'] ?? ''),
                        'state' => $values['state'] ?? null,
                        'wait_event_type' => $values['wait_event_type'] ?? null,
                        'wait_event' => $values['wait_event'] ?? null,
                        'blocking_pids' => $values['blocking_pids'] ?? null,
                    ];
                }, $activities),
                'locks' => array_map(static function (object $row): array {
                    $values = get_object_vars($row);

                    return [
                        'pid' => (int) ($values['pid'] ?? 0),
                        'locktype' => (string) ($values['locktype'] ?? ''),
                        'relation' => $values['relation_name'] ?? null,
                        'mode' => (string) ($values['mode'] ?? ''),
                        'granted' => (bool) ($values['granted'] ?? false),
                    ];
                }, $locks),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);
        } catch (Throwable) {
            fwrite(STDERR, 'PostgreSQL lock diagnostics unavailable.'.PHP_EOL);
        }
    }

    private function zoomCredential(Organization $organization): OrganizationCredential
    {
        return OrganizationCredential::query()
            ->where('organization_id', $organization->getKey())
            ->where('provider', 'zoom')
            ->where('credential_name', (string) config('b2b.credential_name'))
            ->sole();
    }

    /** @return array{0: Organization, 1: User} */
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
