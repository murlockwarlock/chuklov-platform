<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\PainAnalytics;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
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
        self::assertSame(70.83, $data->problems[0]['average_reduction_percent']);
        self::assertSame(2.0, $data->problems[0]['session_1_after']);
        self::assertSame(1.0, $data->problems[0]['session_2_after']);
    }

    public function test_pain_reduction_percent_is_null_when_before_is_zero_or_either_score_is_missing(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $tag = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Нулевая боль',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-10-01 09:00:00', 'UTC'),
            pains: [[
                'name' => 'Без исходной боли',
                'tag_ids' => [$tag->getKey()],
                'before_score' => 0,
                'after_score' => 0,
            ], [
                'name' => 'Без оценки после',
                'tag_ids' => [$tag->getKey()],
                'before_score' => null,
                'after_score' => 5,
            ]],
        ));

        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
        $data = app(PainAnalytics::class)->handle($admin, $period);

        self::assertNull($data->problems[0]['average_reduction_percent']);
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
        app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'),
            pains: [
                ['name' => 'Плечо', 'tag_ids' => [$shoulder->getKey()], 'before_score' => 4, 'after_score' => 1],
            ],
        ));

        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
        $data = app(PainAnalytics::class)->handle($admin, $period, problemTagId: (int) $neck->getKey());

        self::assertCount(1, $data->problems);
        self::assertSame(1, $data->clientCount);
        self::assertSame(1, $data->sessionCount);
        self::assertSame('Шея', $data->problems[0]['name']);
        self::assertSame(7.0, $data->problems[0]['average_before']);
        self::assertSame(3.0, $data->problems[0]['average_after']);
    }

    public function test_problem_axis_numbers_matching_sessions_per_client_and_problem_tag(): void
    {
        [$organization, $admin, $clientA, $specialist] = $this->fixture();
        $clientB = Client::factory()->forOrganization($organization)->create();
        $clientC = Client::factory()->forOrganization($organization)->create();
        $otherOrganization = Organization::factory()->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization, OrganizationRole::Administrator)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create();

        $shoulder = $this->problemTag($organization, 'Плечо', 1);
        $back = $this->problemTag($organization, 'Спина', 2);
        $neck = $this->problemTag($organization, 'Шея', 3);
        $zero = $this->problemTag($organization, 'Нулевая оценка', 4);
        $withoutScore = $this->problemTag($organization, 'Без оценки', 5);
        $otherNeck = $this->problemTag($otherOrganization, 'Шея', 1);

        $this->createPainSession($admin, $clientA, $specialist, '2026-01-01 09:00:00', [['name' => 'Плечо', 'tag_ids' => [$shoulder->getKey()], 'before_score' => 9, 'after_score' => 8]]);
        $this->createPainSession($admin, $clientA, $specialist, '2026-01-02 09:00:00', [['name' => 'Плечо', 'tag_ids' => [$shoulder->getKey()], 'before_score' => 8, 'after_score' => 7]]);
        $this->createPainSession($admin, $clientA, $specialist, '2026-01-03 09:00:00', [['name' => 'Спина', 'tag_ids' => [$back->getKey()], 'before_score' => 9, 'after_score' => 7]]);
        $this->createPainSession($admin, $clientA, $specialist, '2026-01-04 09:00:00', []);
        $this->createPainSession($admin, $clientA, $specialist, '2026-01-05 09:00:00', [['name' => 'Шея', 'tag_ids' => [$neck->getKey()], 'before_score' => 10, 'after_score' => 8]]);
        $this->createPainSession($admin, $clientA, $specialist, '2026-01-06 09:00:00', [['name' => 'Шея', 'tag_ids' => [$neck->getKey()], 'before_score' => 8, 'after_score' => 4]]);
        $this->createPainSession($admin, $clientA, $specialist, '2026-01-07 09:00:00', [['name' => 'Спина', 'tag_ids' => [$back->getKey()], 'before_score' => 5, 'after_score' => 2]]);
        $this->createPainSession($admin, $clientA, $specialist, '2026-01-08 09:00:00', [['name' => 'Шея', 'tag_ids' => [$neck->getKey()], 'before_score' => 3, 'after_score' => 1]]);
        $this->createPainSession($admin, $clientA, $specialist, '2026-01-09 09:00:00', [['name' => 'Нулевая оценка', 'tag_ids' => [$zero->getKey()], 'before_score' => 5, 'after_score' => 0]]);
        $this->createPainSession($admin, $clientA, $specialist, '2026-01-10 09:00:00', [['name' => 'Без оценки', 'tag_ids' => [$withoutScore->getKey()], 'before_score' => 5, 'after_score' => null]]);

        $this->createPainSession($admin, $clientB, $specialist, '2026-01-01 10:00:00', [['name' => 'Шея', 'tag_ids' => [$neck->getKey()], 'before_score' => 8, 'after_score' => 6]]);
        $this->createPainSession($admin, $clientB, $specialist, '2026-01-01 10:00:00', [['name' => 'Шея', 'tag_ids' => [$neck->getKey()], 'before_score' => 4, 'after_score' => 2]]);
        $this->createPainSession($admin, $clientC, $specialist, '2026-01-01 11:00:00', [
            ['name' => 'Шея утром', 'tag_ids' => [$neck->getKey()], 'before_score' => 10, 'after_score' => 8],
            ['name' => 'Шея вечером', 'tag_ids' => [$neck->getKey()], 'before_score' => 8, 'after_score' => 6],
        ]);

        app(OrganizationContext::class)->set($otherOrganization);
        $this->createPainSession($otherAdmin, $otherClient, $otherSpecialist, '2026-01-01 12:00:00', [['name' => 'Шея', 'tag_ids' => [$otherNeck->getKey()], 'before_score' => 10, 'after_score' => 5]]);
        app(OrganizationContext::class)->set($organization);

        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31'],
            'UTC',
            CarbonImmutable::parse('2026-02-01 12:00:00', 'UTC'),
        );

        $data = app(PainAnalytics::class)->handle($admin, $period, problemTagId: (int) $neck->getKey());
        $neckResult = $data->problems[0];

        self::assertSame('problem-chronological-v1', $data->sessionAxisVersion);
        self::assertSame('Порядок сеансов конкретной категории проблемы', $data->sessionAxisLabel);
        self::assertSame(3, $neckResult['client_count']);
        self::assertSame(7, $neckResult['pain_count']);
        self::assertSame(7.0, $neckResult['session_1_after']);
        self::assertSame(3.0, $neckResult['session_2_after']);
        self::assertSame(1.0, $neckResult['session_3_after']);

        $clientResult = app(PainAnalytics::class)->handle($admin, $period, client: $clientA, problemTagId: (int) $neck->getKey())->problems[0];
        self::assertSame(8.0, $clientResult['session_1_after']);
        self::assertSame(4.0, $clientResult['session_2_after']);
        self::assertSame(1.0, $clientResult['session_3_after']);

        $backResult = app(PainAnalytics::class)->handle($admin, $period, problemTagId: (int) $back->getKey())->problems[0];
        self::assertSame(7.0, $backResult['session_1_after']);
        self::assertSame(2.0, $backResult['session_2_after']);
        self::assertNull($backResult['session_3_after']);

        $zeroResult = app(PainAnalytics::class)->handle($admin, $period, problemTagId: (int) $zero->getKey())->problems[0];
        self::assertSame(0.0, $zeroResult['session_1_after']);
        $nullResult = app(PainAnalytics::class)->handle($admin, $period, problemTagId: (int) $withoutScore->getKey())->problems[0];
        self::assertNull($nullResult['session_1_after']);
    }

    public function test_session_axis_semantics_can_be_changed_without_rewriting_session_data(): void
    {
        [$organization, $admin, $client, $specialist] = $this->fixture();
        $otherTag = $this->problemTag($organization, 'Другая проблема', 1);
        $neck = $this->problemTag($organization, 'Шея', 2);
        $this->createPainSession($admin, $client, $specialist, '2026-09-01 09:00:00', [['name' => 'Другая проблема', 'tag_ids' => [$otherTag->getKey()], 'before_score' => 8, 'after_score' => 6]]);
        $this->createPainSession($admin, $client, $specialist, '2026-09-02 09:00:00', [['name' => 'Другая проблема', 'tag_ids' => [$otherTag->getKey()], 'before_score' => 7, 'after_score' => 5]]);
        $this->createPainSession($admin, $client, $specialist, '2026-09-03 09:00:00', [['name' => 'Другая проблема', 'tag_ids' => [$otherTag->getKey()], 'before_score' => 6, 'after_score' => 4]]);
        $this->createPainSession($admin, $client, $specialist, '2026-09-04 09:00:00', []);
        $this->createPainSession($admin, $client, $specialist, '2026-09-05 09:00:00', [['name' => 'Шея', 'tag_ids' => [$neck->getKey()], 'before_score' => 10, 'after_score' => 8]]);
        $this->createPainSession($admin, $client, $specialist, '2026-10-08 09:00:00', [['name' => 'Шея', 'tag_ids' => [$neck->getKey()], 'before_score' => 5, 'after_score' => 2]]);
        $this->createPainSession($admin, $client, $specialist, '2026-10-09 09:00:00', [['name' => 'Шея', 'tag_ids' => [$neck->getKey()], 'before_score' => 4, 'after_score' => 1]]);

        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );

        $default = app(PainAnalytics::class)->handle($admin, $period, problemTagId: (int) $neck->getKey());
        self::assertSame('problem-chronological-v1', $default->sessionAxisVersion);
        self::assertNull($default->problems[0]['session_1_after']);
        self::assertSame(2.0, $default->problems[0]['session_2_after']);
        self::assertSame(1.0, $default->problems[0]['session_3_after']);

        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::AnalyticsSessionAxisSemantics,
            'client_chronological',
        );

        $global = app(PainAnalytics::class)->handle($admin, $period, problemTagId: (int) $neck->getKey());
        self::assertSame('client-chronological-v1', $global->sessionAxisVersion);
        self::assertNull($global->problems[0]['session_1_after']);
        self::assertNull($global->problems[0]['session_2_after']);
        self::assertNull($global->problems[0]['session_3_after']);

        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::AnalyticsSessionAxisSemantics,
            'selected_period_chronological',
        );

        $selectedPeriod = app(PainAnalytics::class)->handle($admin, $period, problemTagId: (int) $neck->getKey());
        self::assertSame('selected-period-chronological-v1', $selectedPeriod->sessionAxisVersion);
        self::assertSame(2.0, $selectedPeriod->problems[0]['session_1_after']);
        self::assertSame(1.0, $selectedPeriod->problems[0]['session_2_after']);
        self::assertNull($selectedPeriod->problems[0]['session_3_after']);

        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::AnalyticsSessionAxisSemantics,
            'problem_chronological',
        );

        $problem = app(PainAnalytics::class)->handle($admin, $period, problemTagId: (int) $neck->getKey());
        self::assertSame('problem-chronological-v1', $problem->sessionAxisVersion);
        self::assertNull($problem->problems[0]['session_1_after']);
        self::assertSame(2.0, $problem->problems[0]['session_2_after']);
        self::assertSame(1.0, $problem->problems[0]['session_3_after']);
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

    private function problemTag(Organization $organization, string $name, int $sortOrder): MedicalProblemTag
    {
        return MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => $name,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);
    }

    /** @param array<int, array<string, mixed>> $pains */
    private function createPainSession(User $admin, Client $client, Specialist $specialist, string $occurredAt, array $pains): void
    {
        app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse($occurredAt, 'UTC'),
            pains: $pains,
        ));
    }
}
