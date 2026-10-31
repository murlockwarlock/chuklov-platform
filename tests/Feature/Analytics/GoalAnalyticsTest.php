<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\GoalAnalytics;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Tracker\Domain\Enums\TrackerTaskFrequency;
use App\Modules\Tracker\Domain\Enums\TrackerTaskType;
use App\Modules\Tracker\Domain\Models\TrackerGoalTag;
use App\Modules\Tracker\Domain\Models\TrackerTask;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GoalAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_goal_categories_are_aggregated_per_client_and_task(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $tag = TrackerGoalTag::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'name' => 'Сон',
            'group_name' => 'Восстановление',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $task = TrackerTask::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'title' => 'Синтетическая задача',
            'task_type' => TrackerTaskType::Practice->value,
            'frequency' => TrackerTaskFrequency::Daily->value,
            'starts_on' => '2026-10-01',
            'active' => true,
            'display_order' => 1,
            'created_by_user_id' => $admin->getKey(),
        ]);
        $task->goalTags()->syncWithPivotValues([$tag->getKey()], [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
        ]);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
        $data = app(GoalAnalytics::class)->handle($admin, $period);

        self::assertSame('Сон', $data->goals[0]['name']);
        self::assertSame(1, $data->goals[0]['client_count']);
        self::assertSame(1, $data->goals[0]['task_count']);
    }

    public function test_goal_analytics_can_filter_to_one_owner_category(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $sleep = TrackerGoalTag::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'name' => 'Сон',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $movement = TrackerGoalTag::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'name' => 'Движение',
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $task = TrackerTask::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'title' => 'Сон',
            'task_type' => TrackerTaskType::Practice->value,
            'frequency' => TrackerTaskFrequency::Daily->value,
            'starts_on' => '2026-10-01',
            'active' => true,
            'display_order' => 1,
            'created_by_user_id' => $admin->getKey(),
        ]);
        $task->goalTags()->syncWithPivotValues([$sleep->getKey()], [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
        ]);
        $secondTask = TrackerTask::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'title' => 'Движение',
            'task_type' => TrackerTaskType::Practice->value,
            'frequency' => TrackerTaskFrequency::Daily->value,
            'starts_on' => '2026-10-02',
            'active' => true,
            'display_order' => 2,
            'created_by_user_id' => $admin->getKey(),
        ]);
        $secondTask->goalTags()->syncWithPivotValues([$movement->getKey()], [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
        ]);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );

        $data = app(GoalAnalytics::class)->handle($admin, $period, goalTagId: (int) $sleep->getKey());

        self::assertCount(1, $data->goals);
        self::assertSame('Сон', $data->goals[0]['name']);
        self::assertSame(1, $data->goals[0]['task_count']);
    }

    /** @return array{0: Organization, 1: User, 2: Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }
}
