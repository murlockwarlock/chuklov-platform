<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\PainAnalytics;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PainAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_problem_analytics_aggregates_structured_tags_and_preserves_null_scores(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $tag = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Шейный отдел',
            'group_name' => 'Синтетика',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $first = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'),
            pains: [[
                'name' => 'Шея',
                'tag_ids' => [$tag->getKey()],
                'before_score' => 6,
                'after_score' => 2,
            ]],
        ));
        $second = app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'),
            pains: [[
                'name' => 'Шея',
                'tag_ids' => [$tag->getKey()],
                'before_score' => 4,
            ]],
        ));
        app(UpdateSession::class)->handle($admin, MedicalSession::findOrFail($second->id), UpdateSessionCommand::fromArray([
            'pain' => null,
            'tests' => null,
            'observations' => null,
            'root_cause_hypothesis' => null,
            'protocol' => null,
            'result' => null,
            'pains' => [[
                ...$second->pains[0]->toArray(),
                'after_score' => 1,
            ]],
        ]));

        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
        $data = app(PainAnalytics::class)->handle($admin, $period);

        self::assertSame(1, $data->clientCount);
        self::assertSame(2, $data->sessionCount);
        self::assertCount(1, $data->problems);
        self::assertSame('Шейный отдел', $data->problems[0]['name']);
        self::assertSame(1, $data->problems[0]['client_count']);
        self::assertSame(5.0, $data->problems[0]['average_before']);
        self::assertSame(1.5, $data->problems[0]['average_after']);
        self::assertSame(3.5, $data->problems[0]['average_reduction']);
        self::assertSame(2.0, $data->problems[0]['session_1_after']);
        self::assertSame(1.0, $data->problems[0]['session_2_after']);
    }

    public function test_problem_analytics_can_filter_to_one_tenant_problem_tag(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $neck = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Шея',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $shoulder = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Плечо',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'),
            pains: [
                ['name' => 'Шея', 'tag_ids' => [$neck->getKey()], 'before_score' => 7, 'after_score' => 3],
                ['name' => 'Плечо', 'tag_ids' => [$shoulder->getKey()], 'before_score' => 5, 'after_score' => 2],
            ],
        ));

        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
        $data = app(PainAnalytics::class)->handle($admin, $period, problemTagId: (int) $neck->getKey());

        self::assertCount(1, $data->problems);
        self::assertSame('Шея', $data->problems[0]['name']);
        self::assertSame(7.0, $data->problems[0]['average_before']);
        self::assertSame(3.0, $data->problems[0]['average_after']);
    }

    /** @return array{0: Organization, 1: User, 2: Client, 3: Specialist} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client, $specialist];
    }
}
