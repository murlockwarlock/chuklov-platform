<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tracker\Application\AssignTrackerTask;
use App\Modules\Tracker\Application\GrantTrackerAccess;
use App\Modules\Tracker\Application\RecordTrackerTaskEntry;
use App\Modules\Tracker\Application\SaveTrackerPlan;
use App\Modules\Tracker\Domain\Enums\TrackerTaskEntryStatus;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use App\Modules\Tracker\Domain\Models\TrackerEntitlement;
use App\Modules\Tracker\Domain\Models\TrackerPlan;
use App\Modules\Tracker\Domain\Models\TrackerTaskEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class MilestoneTwelveTrackerPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

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

        $entitlement = TrackerEntitlement::query()->firstOrFail();
        self::assertSame('2026-09-01 07:00:00+00:00', CarbonImmutable::parse((string) $entitlement->getRawOriginal('starts_at'))->format('Y-m-d H:i:sP'));
        self::assertSame('2026-10-01 07:00:00+00:00', CarbonImmutable::parse((string) $entitlement->getRawOriginal('ends_at'))->format('Y-m-d H:i:sP'));
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

    public function test_postgresql_serializes_duplicate_grants_for_one_client(): void
    {
        $this->requirePostgres();
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $version = app(SaveTrackerPlan::class)->handle($admin, null, 'Старт', true, true, '10.00', 'USD', 30, null, true, 1);
        $plan = $version->plan()->firstOrFail();
        $starts = CarbonImmutable::parse('2026-09-01 12:00:00', 'Asia/Almaty');
        $ends = $starts->addDays(30);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::grantInProcess($organization->getKey(), $admin->getKey(), $client->getKey(), $plan->getKey(), $starts->toIso8601String(), $ends->toIso8601String(), 'Гонка A'),
            static fn (): string => self::grantInProcess($organization->getKey(), $admin->getKey(), $client->getKey(), $plan->getKey(), $starts->toIso8601String(), $ends->toIso8601String(), 'Гонка B'),
        ]);

        self::assertNotContains('error', array_map(
            static fn (string $result): string => str_starts_with($result, 'error:') ? 'error' : $result,
            $results,
        ), implode(', ', $results));
        self::assertCount(2, array_filter($results, static fn (string $result): bool => str_starts_with($result, 'granted:')));
        self::assertCount(1, array_unique($results));
        self::assertSame(1, TrackerEntitlement::query()->where('organization_id', $organization->getKey())->where('client_id', $client->getKey())->where('active', true)->count());
        self::assertSame(1, TrackerEntitlement::query()->where('organization_id', $organization->getKey())->where('client_id', $client->getKey())->count());
    }

    public function test_postgresql_enforces_tracker_task_tenant_links_and_period_uniqueness(): void
    {
        $this->requirePostgres();
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        app(SetOrganizationSetting::class)->handle($admin, OrganizationSettingKey::TrackerFreeMode, true);
        Queue::fake();

        $task = app(AssignTrackerTask::class)->handle(
            actor: $admin,
            client: $client,
            title: 'Задача недели',
            type: TrackerTaskType::Other,
            frequency: TrackerTaskFrequency::Weekly,
            startsOn: CarbonImmutable::today('Asia/Almaty'),
            weekDay: CarbonImmutable::today('Asia/Almaty')->dayOfWeekIso,
        );
        $entry = app(RecordTrackerTaskEntry::class)->handle(
            client: $client,
            taskId: (int) $task->getKey(),
            status: TrackerTaskEntryStatus::Completed,
            comment: 'Отмечено',
        );
        $updated = app(RecordTrackerTaskEntry::class)->handle(
            client: $client,
            taskId: (int) $task->getKey(),
            status: TrackerTaskEntryStatus::NotCompleted,
        );

        self::assertSame($entry->getKey(), $updated->getKey());
        self::assertSame(1, TrackerTaskEntry::query()->where('organization_id', $organization->getKey())->count());

        $otherOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($otherOrganization)->create();
        $foreignLinkError = null;
        try {
            DB::table('tracker_tasks')->insert([
                'organization_id' => $organization->getKey(),
                'client_id' => $foreignClient->getKey(),
                'title' => 'Чужая задача',
                'task_type' => TrackerTaskType::Other->value,
                'frequency' => TrackerTaskFrequency::Daily->value,
                'starts_on' => CarbonImmutable::today('UTC')->toDateString(),
                'active' => true,
                'display_order' => 0,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        } catch (QueryException $exception) {
            $foreignLinkError = $exception->getMessage();
        }

        self::assertNotNull($foreignLinkError);
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

    private static function grantInProcess(
        int $organizationId,
        int $adminId,
        int $clientId,
        int $planId,
        string $startsAt,
        string $endsAt,
        string $reason,
    ): string {
        $organization = Organization::query()->findOrFail($organizationId);
        app(OrganizationContext::class)->set($organization);

        try {
            $entitlement = DB::transaction(function () use ($organizationId, $adminId, $clientId, $planId, $startsAt, $endsAt, $reason): TrackerEntitlement {
                Client::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($clientId)
                    ->lockForUpdate()
                    ->firstOrFail();
                DB::select('SELECT pg_sleep(1)');

                return app(GrantTrackerAccess::class)->handle(
                    actor: User::query()->findOrFail($adminId),
                    client: Client::query()->findOrFail($clientId),
                    plan: TrackerPlan::query()->findOrFail($planId),
                    startsAt: CarbonImmutable::parse($startsAt),
                    endsAt: CarbonImmutable::parse($endsAt),
                    reason: $reason,
                );
            });

            return 'granted:'.$entitlement->getKey();
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.(string) $exception->getCode().':'.$exception->getMessage();
        }
    }
}
