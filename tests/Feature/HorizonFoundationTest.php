<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
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
}
