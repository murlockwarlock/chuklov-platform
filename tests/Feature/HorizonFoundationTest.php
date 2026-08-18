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
        self::assertSame(['default', 'scenarios'], config('horizon.defaults.supervisor-1.queue'));
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
