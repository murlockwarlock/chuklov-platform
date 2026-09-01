<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\B2B\Application\RecordB2bProviderSyncEvent;
use App\Modules\B2B\Application\ScheduleB2bProviderSyncEvents;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Jobs\ProcessB2bProviderSyncEvent;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class HorizonFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_metrics_snapshot_is_scheduled(): void
    {
        self::assertSame(0, Artisan::call('schedule:list'));
        self::assertStringContainsString('horizon:snapshot', Artisan::output());
    }

    public function test_b2b_job_retains_redis_connection_after_serialization(): void
    {
        $job = (new ProcessB2bProviderSyncEvent(42))
            ->onQueue('integrations')
            ->delay(60);
        $restored = unserialize(serialize($job));

        self::assertInstanceOf(ProcessB2bProviderSyncEvent::class, $restored);
        self::assertSame('redis', $restored->connection);
        self::assertSame('integrations', $restored->queue);
        self::assertSame(42, $restored->integrationEventId);
        self::assertSame(60, $restored->delay);
    }

    public function test_staging_has_a_bounded_horizon_supervisor(): void
    {
        $configuration = config('horizon.environments.staging.supervisor-1');

        self::assertIsArray($configuration);
        self::assertSame(2, $configuration['maxProcesses']);
        self::assertSame(1, $configuration['balanceMaxShift']);
        self::assertSame(3, $configuration['balanceCooldown']);
        self::assertSame([
            'default',
            'scenarios',
            'broadcasts',
            'ai-companion',
            'ai-companion-delivery',
            'telegram-typing',
            'referrals',
            (string) config('b2b.queue'),
        ], config('horizon.defaults.supervisor-1.queue'));
    }

    public function test_horizon_consumes_a_configured_b2b_provider_queue(): void
    {
        config()->set('b2b.queue', 'b2b-custom');
        $configuration = require base_path('config/horizon.php');

        self::assertContains(
            'b2b-custom',
            $configuration['defaults']['supervisor-1']['queue'],
        );
    }

    #[DataProvider('acceptedB2bQueueConfigurations')]
    public function test_accepted_b2b_queue_configuration_is_shared_by_producer_and_horizon(?string $configuredQueue, string $expectedQueue): void
    {
        $configuration = $this->loadB2bConfiguration($configuredQueue, $configuredQueue !== null);
        self::assertSame($expectedQueue, $configuration['queue']);
        config()->set('b2b.queue', $configuration['queue']);
        config()->set('queue.default', 'database');
        Queue::fake();

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $lead = B2bLead::factory()->forClient($client)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $call = B2bSalesCall::factory()
            ->forLead($lead)
            ->forSpecialist($specialist)
            ->create([
                'provider_account_id' => 'test-account',
                'provider_host_user_id' => 'test-host',
            ]);
        $event = app(RecordB2bProviderSyncEvent::class)->handle(
            $organization,
            $call,
            VideoMeetingOperation::Create,
        );
        $horizonConfiguration = require base_path('config/horizon.php');

        self::assertContains(
            $expectedQueue,
            $horizonConfiguration['defaults']['supervisor-1']['queue'],
        );
        Queue::assertPushedOn(
            $expectedQueue,
            ProcessB2bProviderSyncEvent::class,
            static function (ProcessB2bProviderSyncEvent $job) use ($event): bool {
                self::assertSame('redis', $job->connection);

                return $job->integrationEventId === $event->getKey();
            },
        );
    }

    public function test_scheduler_dispatches_b2b_provider_events_to_redis_and_the_configured_queue(): void
    {
        config()->set([
            'b2b.queue' => 'b2b.custom',
            'queue.default' => 'database',
        ]);
        Queue::fake();

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $lead = B2bLead::factory()->forClient($client)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $call = B2bSalesCall::factory()
            ->forLead($lead)
            ->forSpecialist($specialist)
            ->create([
                'provider_account_id' => 'test-account',
                'provider_host_user_id' => 'test-host',
            ]);
        $event = app(RecordB2bProviderSyncEvent::class)->handle(
            $organization,
            $call,
            VideoMeetingOperation::Create,
        );

        Queue::fake();

        self::assertSame(1, app(ScheduleB2bProviderSyncEvents::class)->handle());
        Queue::assertPushed(
            ProcessB2bProviderSyncEvent::class,
            static function (ProcessB2bProviderSyncEvent $job) use ($event): bool {
                self::assertSame('redis', $job->connection);
                self::assertSame('b2b.custom', $job->queue);

                return $job->integrationEventId === $event->getKey();
            },
        );
    }

    #[DataProvider('invalidB2bQueueConfigurations')]
    public function test_invalid_b2b_queue_configuration_is_rejected_during_config_bootstrap(string $configuredQueue): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('B2B_QUEUE');

        $this->loadB2bConfiguration($configuredQueue, true);
    }

    public function test_valid_b2b_queue_configuration_survives_evaluated_cached_configuration_semantics(): void
    {
        $configuration = $this->loadB2bConfiguration('b2b.custom', true);
        $evaluatedConfiguration = ['b2b.queue' => $configuration['queue']];

        config()->set($evaluatedConfiguration);

        self::assertSame('b2b.custom', config('b2b.queue'));
    }

    public function test_candidate_config_cache_is_consumed_by_a_fresh_process(): void
    {
        $filesystem = new Filesystem;
        $cacheDirectory = sys_get_temp_dir().'/chuklov-config-cache-'.bin2hex(random_bytes(8));
        $cachePath = $cacheDirectory.'/config.php';
        $filesystem->mkdir($cacheDirectory);
        $environment = [
            'APP_CONFIG_CACHE' => $cachePath,
            'B2B_QUEUE' => 'b2b.custom',
            'QUEUE_CONNECTION' => 'sync',
        ];

        try {
            $cache = new Process(['php', 'artisan', 'config:cache', '--no-ansi'], base_path(), $environment);
            $cache->mustRun();

            $fresh = new Process([
                'php',
                '-r',
                'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode([app()->configurationIsCached(), config("queue.default"), config("b2b.queue"), config("horizon.defaults.supervisor-1.connection"), in_array(config("b2b.queue"), config("horizon.defaults.supervisor-1.queue"), true)]);',
            ], base_path(), $environment);
            $fresh->mustRun();

            self::assertSame([true, 'sync', 'b2b.custom', 'redis', true], json_decode(trim($fresh->getOutput()), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            $filesystem->remove($cacheDirectory);
        }
    }

    public function test_every_m11_production_queue_is_consumed_by_the_bounded_supervisor(): void
    {
        $configuration = config('horizon.defaults.supervisor-1');

        self::assertIsArray($configuration);
        self::assertSame([
            'default',
            'scenarios',
            'broadcasts',
            'ai-companion',
            'ai-companion-delivery',
            'telegram-typing',
            config('referrals.queue'),
            (string) config('b2b.queue'),
        ], $configuration['queue']);
        self::assertSame(10, config('horizon.environments.production.supervisor-1.maxProcesses'));
        self::assertSame(1, config('horizon.environments.production.supervisor-1.balanceMaxShift'));
        self::assertSame(3, config('horizon.environments.production.supervisor-1.balanceCooldown'));
    }

    public function test_horizon_requires_a_privileged_membership_in_the_server_organization(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        config()->set('tenancy.default_organization_id', $organization->id);

        self::assertTrue(Gate::forUser($admin)->allows('viewHorizon'));
        self::assertFalse(Gate::forUser($staff)->allows('viewHorizon'));
    }

    public static function acceptedB2bQueueConfigurations(): array
    {
        return [
            'default' => [null, 'integrations'],
            'custom' => ['b2b-custom', 'b2b-custom'],
            'dot' => ['b2b.custom', 'b2b.custom'],
            'underscore' => ['b2b_custom', 'b2b_custom'],
            'quoted ordinary value' => ['"integrations"', 'integrations'],
            'quoted true literal' => ['"true"', 'true'],
            'quoted false literal' => ['"false"', 'false'],
            'quoted null literal' => ['"null"', 'null'],
            'quoted empty literal' => ['"empty"', 'empty'],
            'one character' => ['A', 'A'],
            'maximum length' => [str_repeat('a', 64), str_repeat('a', 64)],
        ];
    }

    public static function invalidB2bQueueConfigurations(): array
    {
        $configurations = [
            'empty' => [''],
            'true sentinel' => ['true'],
            'false sentinel' => ['false'],
            'null sentinel' => ['null'],
            'empty sentinel' => ['empty'],
            'parenthesized true sentinel' => ['(true)'],
            'parenthesized false sentinel' => ['(false)'],
            'parenthesized null sentinel' => ['(null)'],
            'parenthesized empty sentinel' => ['(empty)'],
            'uppercase true sentinel' => ['TRUE'],
            'whitespace only' => ['   '],
            'leading whitespace' => [' integrations'],
            'trailing whitespace' => ['integrations '],
            'comma' => ['foo,bar'],
            'colon' => ['foo:bar'],
            'leading dash' => ['-foo'],
            'space' => ['foo bar'],
            'command substitution' => ['foo$(id)'],
            'variable' => ['$PATH'],
            'double quote' => ['foo"bar'],
            'single quote' => ["foo'bar"],
            'backslash' => ['foo\\bar'],
            'backtick' => ['foo`id`'],
            'semicolon' => ['foo;bar'],
            'ampersand' => ['foo&bar'],
            'pipe' => ['foo|bar'],
            'parentheses' => ['foo(bar)'],
            'redirection' => ['foo>bar'],
            'reverse redirection' => ['foo<bar'],
            'newline' => ["foo\nbar"],
            'tab' => ["foo\tbar"],
            'null' => ["foo\0bar"],
            'delete control' => ['foo'.chr(127).'bar'],
            'leading dot' => ['.foo'],
            'too long' => [str_repeat('a', 65)],
        ];

        foreach (array_merge(range(0, 31), [127]) as $code) {
            $configurations['control-'.$code] = ['foo'.chr($code).'bar'];
        }

        return $configurations;
    }

    private function loadB2bConfiguration(?string $configuredQueue = null, bool $isConfigured = false): array
    {
        $previousPutenvValue = getenv('B2B_QUEUE');
        $previousEnvIsDefined = array_key_exists('B2B_QUEUE', $_ENV);
        $previousEnvValue = $previousEnvIsDefined ? $_ENV['B2B_QUEUE'] : null;
        $previousServerIsDefined = array_key_exists('B2B_QUEUE', $_SERVER);
        $previousServerValue = $previousServerIsDefined ? $_SERVER['B2B_QUEUE'] : null;

        if ($isConfigured) {
            $value = (string) $configuredQueue;
            putenv('B2B_QUEUE');
            if (! str_contains($value, "\0")) {
                putenv('B2B_QUEUE='.$value);
            }
            $_ENV['B2B_QUEUE'] = $value;
            $_SERVER['B2B_QUEUE'] = $value;
        } else {
            putenv('B2B_QUEUE');
            unset($_ENV['B2B_QUEUE'], $_SERVER['B2B_QUEUE']);
        }

        Env::enablePutenv();

        try {
            return require base_path('config/b2b.php');
        } finally {
            Env::enablePutenv();
            if ($previousPutenvValue === false) {
                putenv('B2B_QUEUE');
            } else {
                putenv('B2B_QUEUE='.$previousPutenvValue);
            }

            if ($previousEnvIsDefined) {
                $_ENV['B2B_QUEUE'] = $previousEnvValue;
            } else {
                unset($_ENV['B2B_QUEUE']);
            }

            if ($previousServerIsDefined) {
                $_SERVER['B2B_QUEUE'] = $previousServerValue;
            } else {
                unset($_SERVER['B2B_QUEUE']);
            }
        }
    }
}
