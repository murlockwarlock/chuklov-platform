<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tracker\Application\GrantTrackerAccess;
use App\Modules\Tracker\Application\SaveTrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerEntitlement;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MilestoneTwelveTrackerPostgresTest extends TestCase
{
    use DatabaseTruncation;

    public function test_postgresql_enforces_plan_version_uniqueness_and_tenant_links(): void
    {
        $this->requirePostgres();
        [$organization, $admin] = $this->fixture();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Старт', true, true, '10.00', 'USD', 30, null, true, 1);

        $duplicateVersionError = null;
        try {
            DB::table('tracker_plan_versions')->insert([
                'organization_id' => $organization->getKey(),
                'tracker_plan_id' => $version->tracker_plan_id,
                'version' => $version->version,
                'price_minor' => 1000,
                'currency' => 'USD',
                'duration_days' => 30,
            ]);
        } catch (QueryException $exception) {
            $duplicateVersionError = $exception->getMessage();
        }
        self::assertNotNull($duplicateVersionError);

        $otherOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($otherOrganization)->create();
        $foreignLinkError = null;
        try {
            DB::table('tracker_entitlements')->insert([
                'organization_id' => $organization->getKey(),
                'client_id' => $foreignClient->getKey(),
                'active' => true,
                'starts_at' => now('UTC'),
                'ends_at' => now('UTC')->addDay(),
                'source' => 'manual',
                'reason' => 'invalid tenant link',
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        } catch (QueryException $exception) {
            $foreignLinkError = $exception->getMessage();
        }
        self::assertNotNull($foreignLinkError);
    }

    public function test_postgresql_allows_only_one_active_entitlement_and_preserves_utc_instants(): void
    {
        $this->requirePostgres();
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Старт', true, true, '10.00', 'USD', 30, null, true, 1);
        $plan = $version->plan()->firstOrFail();
        $starts = CarbonImmutable::parse('2026-09-01 12:00:00', 'Asia/Almaty');
        $ends = $starts->addDays(30);
        app(GrantTrackerAccess::class)->handle($admin, $client, $plan, $starts, $ends, 'Проверка периода');

        self::assertSame('2026-09-01 06:00:00+00', CarbonImmutable::parse((string) TrackerEntitlement::query()->firstOrFail()->getRawOriginal('starts_at'))->format('Y-m-d H:i:sP'));
        $duplicateActiveError = null;
        try {
            DB::table('tracker_entitlements')->insert([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'active' => true,
                'starts_at' => now('UTC'),
                'ends_at' => now('UTC')->addDay(),
                'source' => 'manual',
                'reason' => 'duplicate active',
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        } catch (QueryException $exception) {
            $duplicateActiveError = $exception->getMessage();
        }
        self::assertNotNull($duplicateActiveError);
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL is required for tracker persistence verification.');
        }
    }
}
