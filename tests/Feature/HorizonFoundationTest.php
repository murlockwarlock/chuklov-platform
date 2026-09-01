<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\B2B\Application\RecordB2bProviderSyncEvent;
use App\Modules\B2B\Domain\Enums\VideoMeetingOperation;
use App\Modules\B2B\Domain\Models\B2bLead;
use App\Modules\B2B\Domain\Models\B2bSalesCall;
use App\Modules\B2B\Jobs\ProcessB2bProviderSyncEvent;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HorizonFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_horizon_metrics_snapshot_is_scheduled(): void
    {
        self::assertSame(0, Artisan::call('schedule:list'));
        self::assertStringContainsString('horizon:snapshot', Artisan::output());
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
        Queue::fake();

        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $lead = B2bLead::factory()->forClient($client)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $call = B2bSalesCall::factory()
            ->forLead($lead)
            ->forSpecialist($specialist)
            ->create();
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
            static fn (ProcessB2bProviderSyncEvent $job): bool => $job->integrationEventId === $event->getKey(),
        );
    }

    public function test_blank_b2b_queue_configuration_is_rejected_during_config_bootstrap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('B2B_QUEUE');

        $this->loadB2bConfiguration('', true);
    }

    public function test_comma_containing_b2b_queue_configuration_is_rejected_during_config_bootstrap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('B2B_QUEUE');

        $this->loadB2bConfiguration('foo,bar', true);
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
            'surrounding whitespace' => ['  b2b-whitespace  ', 'b2b-whitespace'],
        ];
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
            putenv('B2B_QUEUE='.$value);
            $_ENV['B2B_QUEUE'] = $value;
            $_SERVER['B2B_QUEUE'] = $value;
        } else {
            putenv('B2B_QUEUE');
            unset($_ENV['B2B_QUEUE'], $_SERVER['B2B_QUEUE']);
        }

        try {
            return require base_path('config/b2b.php');
        } finally {
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
