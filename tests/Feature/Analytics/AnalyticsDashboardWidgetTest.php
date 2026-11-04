<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\AnalyticsConsole;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AnalyticsAcquisitionWidget;
use App\Filament\Widgets\AnalyticsAiFailuresWidget;
use App\Filament\Widgets\AnalyticsFinanceWidget;
use App\Filament\Widgets\AnalyticsIngestionFailuresWidget;
use App\Filament\Widgets\AnalyticsKpiWidget;
use App\Filament\Widgets\AnalyticsSchedulingWidget;
use App\Filament\Widgets\UpcomingBookingsWidget;
use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Domain\Enums\PainScoreBasis;
use App\Modules\Attribution\Application\AttributionSourceCatalog;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Feedback\Domain\Models\FeedbackTheme;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Tracker\Domain\Models\TrackerGoalTag;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

final class AnalyticsDashboardWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ru');
    }

    protected function tearDown(): void
    {
        app()->setLocale('ru');

        parent::tearDown();
    }

    public function test_dashboard_keeps_upcoming_bookings_and_registers_one_shared_filter(): void
    {
        $dashboard = new Dashboard;

        self::assertSame(1, $dashboard->getColumns());
        self::assertSame(UpcomingBookingsWidget::class, $dashboard->getWidgets()[0]);
        self::assertSame(AnalyticsKpiWidget::class, $dashboard->getWidgets()[1]);
        self::assertContains(AnalyticsKpiWidget::class, $dashboard->getWidgets());
        self::assertContains(UpcomingBookingsWidget::class, $dashboard->getWidgets());
        self::assertContains(AnalyticsAcquisitionWidget::class, $dashboard->getWidgets());
        self::assertContains(AnalyticsSchedulingWidget::class, $dashboard->getWidgets());
        self::assertContains(AnalyticsFinanceWidget::class, $dashboard->getWidgets());
        self::assertContains(AnalyticsAiFailuresWidget::class, $dashboard->getWidgets());
        self::assertContains(AnalyticsIngestionFailuresWidget::class, $dashboard->getWidgets());

        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSuccessful()
            ->assertActionExists('filter')
            ->set('filters', ['period' => DashboardPeriod::Today])
            ->assertSet('filters.period', DashboardPeriod::Today);
    }

    public function test_analytics_console_keeps_multiple_dimensions_and_runs_analysis(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->assertSuccessful()
            ->fillForm([
                'dimensions' => ['pain', 'acquisition', 'scheduling', 'segments'],
                'period' => DashboardPeriod::Today,
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён')
            ->assertSee('Источники клиентов');

        self::assertArrayHasKey('pain', $component->instance()->results);
        self::assertArrayHasKey('acquisition', $component->instance()->results);
        self::assertArrayHasKey('scheduling', $component->instance()->results);
        self::assertArrayHasKey('segments', $component->instance()->results);
        self::assertSame(['pain', 'acquisition', 'scheduling', 'segments'], $component->instance()->data['dimensions']);
    }

    public function test_analytics_console_applies_the_selected_client_to_each_requested_dimension(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $selected = Client::factory()->forOrganization($organization)->create(['full_name' => 'Selected client']);
        $other = Client::factory()->forOrganization($organization)->create(['full_name' => 'Other client']);
        DB::table('clients')->whereIn('id', [$selected->getKey(), $other->getKey()])->update([
            'created_at' => '2026-10-10 10:00:00',
            'updated_at' => '2026-10-10 10:00:00',
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        foreach ([$selected, $other] as $client) {
            $booking = Booking::factory()
                ->forClient($client)
                ->forSpecialist($specialist)
                ->forService($service)
                ->create([
                    'status' => 'completed',
                    'starts_at' => '2026-10-10 12:00:00',
                    'created_at' => '2026-10-10 10:00:00',
                    'updated_at' => '2026-10-10 10:00:00',
                ]);
            BookingEvent::factory()->forBooking($booking)->create([
                'event_type' => 'completed',
                'occurred_at' => '2026-10-10 12:30:00',
            ]);
        }
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['acquisition', 'scheduling', 'demographics', 'services', 'booking_statuses'],
                'client_id' => $selected->getKey(),
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-11',
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён');

        self::assertSame($selected->getKey(), $component->instance()->data['client_id']);
        self::assertSame(1, $component->instance()->results['acquisition']['new_clients']);
        self::assertSame(1, $component->instance()->results['scheduling']['bookings']);
        self::assertSame(1, $component->instance()->results['demographics']['client_count']);
        self::assertSame(1, $component->instance()->results['services']['visit_count']);
        self::assertSame(1, $component->instance()->results['booking_statuses']['statuses'][0]['count']);
    }

    public function test_analytics_console_preserves_and_applies_problem_tag_filter(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $tag = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Шея',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['pain'],
                'problem_tag_id' => $tag->getKey(),
                'period' => DashboardPeriod::Today,
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён');

        self::assertSame($tag->getKey(), $component->instance()->data['problem_tag_id']);
        self::assertArrayHasKey('pain', $component->instance()->results);
    }

    public function test_analytics_console_uses_the_selected_pain_score_basis_for_preview_and_analysis(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $afterClient = Client::factory()->forOrganization($organization)->create();
        $beforeClient = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);
        app(CreateSession::class)->handle($admin, $afterClient, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-10-10 10:00:00', 'UTC'),
            pains: [['name' => 'После сеанса', 'before_score' => null, 'after_score' => 2]],
        ));
        app(CreateSession::class)->handle($admin, $beforeClient, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse('2026-10-10 11:00:00', 'UTC'),
            pains: [['name' => 'До сеанса', 'before_score' => 4, 'after_score' => null]],
        ));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->assertSuccessful()
            ->assertSee('Источник оценки боли')
            ->assertSee('После сеанса')
            ->fillForm([
                'dimensions' => ['pain'],
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-31',
                'cohort_pain_score_basis' => PainScoreBasis::SessionAfter->value,
                'cohort_has_pain_score' => '1',
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён');

        self::assertSame(PainScoreBasis::SessionAfter->value, $component->instance()->data['cohort_pain_score_basis']);
        self::assertSame(1, $component->instance()->cohortPreviewCount);
        self::assertStringContainsString('оценка боли: После сеанса', (string) $component->instance()->cohortDrilldownReason);

        $component
            ->fillForm(['cohort_pain_score_basis' => PainScoreBasis::SessionBefore->value])
            ->call('analyze')
            ->assertNotified('Анализ обновлён');

        self::assertSame(PainScoreBasis::SessionBefore->value, $component->instance()->data['cohort_pain_score_basis']);
        self::assertSame(1, $component->instance()->cohortPreviewCount);
        self::assertStringContainsString('оценка боли: До сеанса', (string) $component->instance()->cohortDrilldownReason);
    }

    public function test_analytics_console_drills_down_from_a_service_aggregate_to_the_same_cohort_query(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $selected = Client::factory()->forOrganization($organization)->create(['full_name' => 'Клиент услуги']);
        $other = Client::factory()->forOrganization($organization)->create(['full_name' => 'Другой клиент']);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create(['name' => 'Комплексная терапия']);
        $otherService = Service::factory()->forOrganization($organization)->create(['name' => 'Массаж']);
        foreach ([[$selected, $service], [$other, $otherService]] as [$client, $bookingService]) {
            Booking::factory()
                ->forClient($client)
                ->forSpecialist($specialist)
                ->forService($bookingService)
                ->create([
                    'status' => 'completed',
                    'starts_at' => '2026-10-10 12:00:00',
                    'ends_at' => '2026-10-10 13:00:00',
                    'blocking_ends_at' => '2026-10-10 13:00:00',
                ]);
        }
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['services'],
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-11',
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён')
            ->call('showAggregateClients', 'services', $service->getKey());

        self::assertSame([$selected->full_name], array_column($component->instance()->cohortClients, 'name'));
        self::assertStringContainsString('услуга «Комплексная терапия»', (string) $component->instance()->cohortDrilldownReason);
    }

    public function test_service_aggregate_drilldown_intersects_base_or_without_mutating_form_or_session_state(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $serviceA = Service::factory()->forOrganization($organization)->create(['name' => 'Сервис A']);
        $serviceB = Service::factory()->forOrganization($organization)->create(['name' => 'Сервис B']);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $clientA = Client::factory()->forOrganization($organization)->create(['full_name' => 'Клиент A']);
        $clientB = Client::factory()->forOrganization($organization)->create(['full_name' => 'Клиент B']);
        $clientBoth = Client::factory()->forOrganization($organization)->create(['full_name' => 'Клиент A+B']);
        $foreignOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create(['full_name' => 'Чужой клиент']);
        $foreignSpecialist = Specialist::factory()->forOrganization($foreignOrganization)->create();
        $foreignService = Service::factory()->forOrganization($foreignOrganization)->create(['name' => 'Сервис A']);
        $bookingAttributes = [
            'status' => 'completed',
            'starts_at' => '2026-10-10 12:00:00',
            'ends_at' => '2026-10-10 13:00:00',
            'blocking_ends_at' => '2026-10-10 13:00:00',
        ];
        Booking::factory()->forClient($clientA)->forSpecialist($specialist)->forService($serviceA)->create($bookingAttributes);
        Booking::factory()->forClient($clientB)->forSpecialist($specialist)->forService($serviceB)->create($bookingAttributes);
        Booking::factory()->forClient($clientBoth)->forSpecialist($specialist)->forService($serviceA)->create($bookingAttributes);
        Booking::factory()->forClient($clientBoth)->forSpecialist($specialist)->forService($serviceB)->create($bookingAttributes);
        Booking::factory()->forClient($foreignClient)->forSpecialist($foreignSpecialist)->forService($foreignService)->create($bookingAttributes);
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['services'],
                'cohort_service_ids' => [$serviceA->getKey(), $serviceB->getKey()],
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-11',
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён');
        $beforeData = $component->instance()->data;
        $beforeSession = session()->get('analytics.console.state.'.$organization->getKey());

        $component->call('showAggregateClients', 'services', $serviceA->getKey());

        self::assertSame(['Клиент A', 'Клиент A+B'], array_column($component->instance()->cohortClients, 'name'));
        self::assertSame(2, $component->instance()->cohortPreviewCount);
        self::assertSame($beforeData, $component->instance()->data);
        self::assertSame($beforeSession, session()->get('analytics.console.state.'.$organization->getKey()));
        self::assertStringContainsString('услуга «Сервис A»', (string) $component->instance()->cohortDrilldownReason);

        $component->call('showAggregateClients', 'services', $serviceB->getKey());

        self::assertSame(['Клиент A+B', 'Клиент B'], array_column($component->instance()->cohortClients, 'name'));
        self::assertSame(2, $component->instance()->cohortPreviewCount);
        self::assertSame(['Сервис A', 'Сервис B'], $component->instance()->results['services']['services'] !== []
            ? array_column($component->instance()->results['services']['services'], 'name')
            : []);

        $component
            ->call('export')
            ->assertFileDownloaded('analytics-2026-10-01-2026-10-11.xlsx');
        self::assertSame(3, $component->instance()->cohortPreviewCount);
    }

    public function test_problem_aggregate_drilldown_intersects_any_without_turning_it_into_all_or_duplication(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $neck = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Шея',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $back = MedicalProblemTag::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'Спина',
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $neckClient = Client::factory()->forOrganization($organization)->create(['full_name' => 'Только шея']);
        $backClient = Client::factory()->forOrganization($organization)->create(['full_name' => 'Только спина']);
        $bothClient = Client::factory()->forOrganization($organization)->create(['full_name' => 'Шея и спина']);
        $singleSessionClient = Client::factory()->forOrganization($organization)->create(['full_name' => 'Две боли в одном сеансе']);
        $foreignOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $foreignAdmin = User::factory()->forOrganization($foreignOrganization, OrganizationRole::Administrator)->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create(['full_name' => 'Чужая шея']);
        $foreignSpecialist = Specialist::factory()->forOrganization($foreignOrganization)->create();
        $foreignNeck = MedicalProblemTag::query()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'name' => 'Шея',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        app(OrganizationContext::class)->set($organization);
        $this->createPainSession($admin, $neckClient, $specialist, '2026-10-05 09:00:00', [[
            'name' => 'Шея', 'tag_ids' => [$neck->getKey()], 'before_score' => 8, 'after_score' => 6,
        ]]);
        $this->createPainSession($admin, $backClient, $specialist, '2026-10-05 10:00:00', [[
            'name' => 'Спина', 'tag_ids' => [$back->getKey()], 'before_score' => 8, 'after_score' => 6,
        ]]);
        $this->createPainSession($admin, $bothClient, $specialist, '2026-10-05 11:00:00', [[
            'name' => 'Шея', 'tag_ids' => [$neck->getKey()], 'before_score' => 8, 'after_score' => 6,
        ]]);
        $this->createPainSession($admin, $bothClient, $specialist, '2026-10-05 12:00:00', [[
            'name' => 'Спина', 'tag_ids' => [$back->getKey()], 'before_score' => 8, 'after_score' => 6,
        ]]);
        $this->createPainSession($admin, $singleSessionClient, $specialist, '2026-10-05 13:00:00', [
            ['name' => 'Шея утром', 'tag_ids' => [$neck->getKey()], 'before_score' => 8, 'after_score' => 8],
            ['name' => 'Шея вечером', 'tag_ids' => [$neck->getKey()], 'before_score' => 8, 'after_score' => 6],
        ]);
        app(OrganizationContext::class)->set($foreignOrganization);
        $this->createPainSession($foreignAdmin, $foreignClient, $foreignSpecialist, '2026-10-05 14:00:00', [[
            'name' => 'Шея', 'tag_ids' => [$foreignNeck->getKey()], 'before_score' => 8, 'after_score' => 6,
        ]]);
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['pain'],
                'cohort_problem_tag_ids' => [$neck->getKey(), $back->getKey()],
                'cohort_problem_tag_mode' => 'any',
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-11',
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён');
        $beforeData = $component->instance()->data;
        $beforeSession = session()->get('analytics.console.state.'.$organization->getKey());

        $component->call('showAggregateClients', 'pain', $neck->getKey());

        self::assertSame(['Две боли в одном сеансе', 'Только шея', 'Шея и спина'], array_column($component->instance()->cohortClients, 'name'));
        self::assertSame(3, $component->instance()->cohortPreviewCount);
        self::assertSame('any', $component->instance()->data['cohort_problem_tag_mode']);
        self::assertSame($beforeData, $component->instance()->data);
        self::assertSame($beforeSession, session()->get('analytics.console.state.'.$organization->getKey()));

        $component->call('showAggregateClients', 'pain', $back->getKey());

        self::assertSame(['Только спина', 'Шея и спина'], array_column($component->instance()->cohortClients, 'name'));
        self::assertSame('any', $component->instance()->data['cohort_problem_tag_mode']);
    }

    public function test_source_aggregate_uses_stable_key_and_exact_new_client_period(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        app(AttributionSourceCatalog::class)->save($admin, [
            ['source_key' => 'instagram', 'label' => 'Instagram владелец', 'aliases' => [], 'is_active' => true, 'sort_order' => 1],
            ['source_key' => 'telegram', 'label' => 'Telegram владелец', 'aliases' => [], 'is_active' => true, 'sort_order' => 2],
        ]);
        $instagram = Client::factory()->forOrganization($organization)->create(['full_name' => 'Instagram клиент', 'created_at' => '2026-10-05 10:00:00']);
        $telegram = Client::factory()->forOrganization($organization)->create(['full_name' => 'Telegram клиент', 'created_at' => '2026-10-05 10:00:00']);
        $oldInstagram = Client::factory()->forOrganization($organization)->create(['full_name' => 'Старый Instagram', 'created_at' => '2026-09-05 10:00:00']);
        $foreignOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create(['full_name' => 'Чужой Instagram', 'created_at' => '2026-10-05 10:00:00']);
        $this->createAttribution($organization, $instagram, 'instagram');
        $this->createAttribution($organization, $telegram, 'telegram');
        $this->createAttribution($organization, $oldInstagram, 'instagram');
        $this->createAttribution($foreignOrganization, $foreignClient, 'instagram');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['acquisition'],
                'cohort_source_keys' => ['instagram', 'telegram'],
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-11',
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён');
        $beforeData = $component->instance()->data;
        $beforeSession = session()->get('analytics.console.state.'.$organization->getKey());
        $instagramRow = collect($component->instance()->results['acquisition']['sources'])->firstWhere('key', 'instagram');

        self::assertSame('known', $instagramRow['kind']);
        self::assertSame(1, $instagramRow['count']);
        $component->call('showAggregateClients', 'acquisition', 'instagram');

        self::assertSame(['Instagram клиент'], array_column($component->instance()->cohortClients, 'name'));
        self::assertSame(1, $component->instance()->cohortPreviewCount);
        self::assertSame($beforeData, $component->instance()->data);
        self::assertSame($beforeSession, session()->get('analytics.console.state.'.$organization->getKey()));
        self::assertStringContainsString('Instagram владелец', (string) $component->instance()->cohortDrilldownReason);
    }

    private function createAttribution(Organization $organization, Client $client, string $source): void
    {
        $attribution = new ClientAttribution;
        $attribution->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'source_type' => 'source',
            'source' => $source,
            'capture_channel' => 'test',
            'capture_context' => 'analytics',
            'captured_at' => '2026-10-05 10:00:00',
            'accepted_at' => '2026-10-05 10:00:00',
        ])->save();
    }

    /** @param array<int, mixed> $pains */
    private function createPainSession(User $admin, Client $client, Specialist $specialist, string $occurredAt, array $pains): void
    {
        app(CreateSession::class)->handle($admin, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: CarbonImmutable::parse($occurredAt, 'UTC'),
            pains: $pains,
        ));
    }

    public function test_analytics_console_preserves_taxonomy_filters_for_multiple_dimensions(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $theme = FeedbackTheme::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'name' => 'Восстановление',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $goal = TrackerGoalTag::query()->forceCreate([
            'organization_id' => $organization->getKey(),
            'name' => 'Сон',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['feedback', 'goals'],
                'feedback_theme_id' => $theme->getKey(),
                'goal_tag_id' => $goal->getKey(),
                'period' => DashboardPeriod::Today,
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён');

        self::assertSame($theme->getKey(), $component->instance()->data['feedback_theme_id']);
        self::assertSame($goal->getKey(), $component->instance()->data['goal_tag_id']);
        self::assertArrayHasKey('feedback', $component->instance()->results);
        self::assertArrayHasKey('goals', $component->instance()->results);
    }

    public function test_analytics_console_preserves_and_applies_source_filter(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $selected = Client::factory()->forOrganization($organization)->create();
        $other = Client::factory()->forOrganization($organization)->create();
        DB::table('clients')->whereIn('id', [$selected->getKey(), $other->getKey()])->update([
            'created_at' => '2026-10-10 10:00:00',
            'updated_at' => '2026-10-10 10:00:00',
        ]);
        foreach ([[$selected, 'instagram_ads'], [$other, 'telegram']] as [$client, $source]) {
            $attribution = new ClientAttribution;
            $attribution->forceFill([
                'organization_id' => $organization->getKey(),
                'client_id' => $client->getKey(),
                'source_type' => 'source',
                'source' => $source,
                'capture_channel' => 'portal',
                'capture_context' => 'test',
                'captured_at' => now(),
                'accepted_at' => now(),
            ])->save();
        }
        app(OrganizationContext::class)->set($organization);
        app(AttributionSourceCatalog::class)->save($admin, [[
            'source_key' => 'social_media',
            'label' => 'Социальные сети',
            'aliases' => [['value' => 'instagram_ads']],
            'is_active' => true,
            'sort_order' => 1,
        ]]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['acquisition'],
                'source_key' => 'social_media',
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-11',
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён');

        self::assertSame('social_media', $component->instance()->data['source_key']);
        self::assertSame(1, $component->instance()->results['acquisition']['new_clients']);
        self::assertSame('Социальные сети', $component->instance()->results['acquisition']['sources'][0]['label']);
    }

    public function test_analytics_console_preserves_purchase_and_booking_status_filters(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['purchases', 'booking_statuses'],
                'purchase_type' => 'program',
                'booking_status' => 'cancelled',
                'period' => DashboardPeriod::Today,
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён');

        self::assertSame('program', $component->instance()->data['purchase_type']);
        self::assertSame('cancelled', $component->instance()->data['booking_status']);
        self::assertSame([], $component->instance()->results['purchases']['types']);
        self::assertSame([['key' => 'cancelled', 'label' => 'Отменённые', 'count' => 0]], $component->instance()->results['booking_statuses']['statuses']);
    }

    public function test_analytics_console_reports_a_dimension_error_without_rendering_partial_result_shape(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $authorizer = Mockery::mock(OrganizationAuthorizer::class, [app(OrganizationContext::class)])->makePartial();
        $authorizer->shouldReceive('allows')->andReturn(true);
        $authorizationCalls = 0;
        $authorizer->shouldReceive('authorize')->andReturnUsing(
            function (User $actor, Organization $currentOrganization, OrganizationPermission $permission) use (&$authorizationCalls): mixed {
                $authorizationCalls++;
                if ($authorizationCalls > 1) {
                    throw new AuthorizationException;
                }

                return $actor->membershipFor($currentOrganization);
            },
        );
        $this->app->instance(OrganizationAuthorizer::class, $authorizer);
        Log::shouldReceive('warning')
            ->once()
            ->with('analytics.dimension_failed', Mockery::on(
                static fn (array $context): bool => $context['organization_id'] === $organization->getKey()
                    && $context['dimension'] === 'scheduling'
                    && $context['exception'] === AuthorizationException::class
                    && ! array_key_exists('message', $context),
            ));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['scheduling'],
                'period' => DashboardPeriod::Today,
            ])
            ->call('analyze')
            ->assertNotified('Анализ завершён частично')
            ->assertSee('Некоторые показатели недоступны')
            ->assertSee('Записи и визиты')
            ->assertSee('Не удалось получить результаты выбранных показателей');

        self::assertSame([], $component->instance()->results);
        self::assertSame(['scheduling' => 'Записи и визиты'], $component->instance()->dimensionErrors);
    }

    public function test_analytics_console_keeps_available_dimensions_and_warns_on_partial_failure(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $authorizer = Mockery::mock(OrganizationAuthorizer::class, [app(OrganizationContext::class)])->makePartial();
        $authorizer->shouldReceive('allows')->andReturn(true);
        $authorizer->shouldReceive('authorize')->andReturnUsing(
            static function (User $actor, Organization $currentOrganization, OrganizationPermission $permission): mixed {
                if ($permission === OrganizationPermission::ViewScheduling) {
                    throw new \RuntimeException('synthetic dimension failure');
                }

                return $actor->membershipFor($currentOrganization);
            },
        );
        $this->app->instance(OrganizationAuthorizer::class, $authorizer);

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->set('cohortClients', [['name' => 'Устаревший клиент']])
            ->fillForm([
                'dimensions' => ['acquisition', 'scheduling'],
                'period' => DashboardPeriod::Today,
            ])
            ->call('analyze')
            ->assertNotified('Анализ завершён частично')
            ->assertSee('Некоторые показатели недоступны')
            ->assertSee('Записи и визиты');

        self::assertArrayHasKey('acquisition', $component->instance()->results);
        self::assertArrayNotHasKey('scheduling', $component->instance()->results);
        self::assertSame([], $component->instance()->cohortClients);
        self::assertSame(['scheduling' => 'Записи и визиты'], $component->instance()->dimensionErrors);
    }

    public function test_analytics_export_does_not_return_a_partial_workbook_when_a_dimension_fails(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $authorizer = Mockery::mock(OrganizationAuthorizer::class, [app(OrganizationContext::class)])->makePartial();
        $authorizer->shouldReceive('allows')->andReturn(true);
        $authorizer->shouldReceive('authorize')->andReturnUsing(
            static function (User $actor, Organization $currentOrganization, OrganizationPermission $permission): mixed {
                if ($permission === OrganizationPermission::ViewScheduling) {
                    throw new \RuntimeException('synthetic export failure');
                }

                return $actor->membershipFor($currentOrganization);
            },
        );
        $this->app->instance(OrganizationAuthorizer::class, $authorizer);

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['acquisition', 'scheduling'],
                'period' => DashboardPeriod::Today,
            ])
            ->call('export')
            ->assertNotified('Excel не сформирован')
            ->assertNoFileDownloaded()
            ->assertSee('Некоторые показатели недоступны');

        self::assertSame(['scheduling' => 'Записи и визиты'], $component->instance()->dimensionErrors);
    }

    public function test_analytics_console_rejects_invalid_custom_period_and_exports_after_correction(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['acquisition'],
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-10-02',
                'end_date' => '2026-10-01',
            ])
            ->call('analyze')
            ->assertNotified('Не удалось выполнить анализ')
            ->assertHasErrors(['data.end_date']);

        self::assertSame([], $component->instance()->results);
        self::assertSame('invalid', $component->instance()->cohortPreviewStatus);
        self::assertNull($component->instance()->cohortPreviewCount);
        self::assertNotNull($component->instance()->cohortPreviewError);

        $component
            ->call('export')
            ->assertNotified('Не удалось выполнить экспорт')
            ->assertNoFileDownloaded()
            ->fillForm([
                'dimensions' => ['acquisition'],
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-10-01',
                'end_date' => '2026-10-02',
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён')
            ->call('export')
            ->assertFileDownloaded('analytics-2026-10-01-2026-10-02.xlsx');

        self::assertSame('success', $component->instance()->cohortPreviewStatus);
        self::assertSame(0, $component->instance()->cohortPreviewCount);
        self::assertNull($component->instance()->cohortPreviewError);
    }

    public function test_analytics_console_uses_human_messages_for_missing_custom_dates(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['acquisition'],
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-10-01',
                'end_date' => null,
            ])
            ->call('analyze')
            ->assertSee('Укажите дату окончания произвольного периода.')
            ->assertDontSee('validation.required_if');

        self::assertStringContainsString('novalidate="novalidate"', $component->html());
    }

    public function test_analytics_widgets_render_without_replacing_upcoming_widget(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(AnalyticsAcquisitionWidget::class)
            ->assertSuccessful()
            ->assertSee('Привлечение')
            ->assertSee('Новые клиенты')
            ->assertSee('Источники новых клиентов');
        Livewire::actingAs($admin)
            ->test(AnalyticsKpiWidget::class)
            ->assertSuccessful()
            ->assertSee('Ключевые показатели')
            ->assertSee('Новые клиенты')
            ->assertSee('Завершённые визиты');
        Livewire::actingAs($admin)
            ->test(AnalyticsSchedulingWidget::class)
            ->assertSuccessful()
            ->assertSee('Записи и визиты')
            ->assertSee('Завершённые визиты')
            ->assertSee('Удержание клиентов')
            ->assertSee('Неявки');
        Livewire::actingAs($admin)
            ->test(AnalyticsFinanceWidget::class)
            ->assertSuccessful()
            ->assertSee('Финансы')
            ->assertSee('Оплата и задолженность за выбранный период')
            ->assertSee('Расчёт недоступен');
        Livewire::actingAs($admin)
            ->test(AnalyticsAiFailuresWidget::class)
            ->assertSuccessful()
            ->assertSee('Ошибки запусков ИИ')
            ->assertSee('Ошибки при обработке запросов за выбранный период');
        Livewire::actingAs($admin)
            ->test(AnalyticsIngestionFailuresWidget::class)
            ->assertSuccessful()
            ->assertSee('Ошибки обработки знаний')
            ->assertSee('Ошибки обработки материалов за выбранный период');
        Livewire::actingAs($admin)
            ->test(UpcomingBookingsWidget::class)
            ->assertSuccessful()
            ->assertSee('Ближайшие записи');
    }

    public function test_dashboard_and_kpi_widget_follow_the_english_crm_locale(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app()->setLocale('en');

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSuccessful()
            ->assertSee('Analytics')
            ->assertSee('Period');

        Livewire::actingAs($admin)
            ->test(AnalyticsKpiWidget::class)
            ->assertSuccessful()
            ->assertSee('Key metrics')
            ->assertSee('New clients');

        Livewire::actingAs($admin)
            ->test(UpcomingBookingsWidget::class)
            ->assertSuccessful()
            ->assertSee('Upcoming appointments');
    }

    public function test_finance_widget_is_hidden_and_financial_values_do_not_enter_other_widgets_without_permission(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        $authorizer = Mockery::mock(OrganizationAuthorizer::class, [app(OrganizationContext::class)])->makePartial();
        $authorizer->shouldReceive('allows')->andReturnUsing(
            static fn (User $actor, Organization $currentOrganization, OrganizationPermission $permission): bool => $permission !== OrganizationPermission::ViewFinance,
        );
        $this->app->instance(OrganizationAuthorizer::class, $authorizer);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        self::assertFalse(AnalyticsFinanceWidget::canView());
        self::assertNotContains(AnalyticsFinanceWidget::class, (new Dashboard)->getVisibleWidgets());

        Livewire::actingAs($admin)
            ->test(AnalyticsKpiWidget::class)
            ->assertSuccessful()
            ->assertDontSee('Выручка')
            ->assertDontSee('Дебиторская задолженность')
            ->assertDontSee('USD');

        Livewire::actingAs($admin)
            ->test(AnalyticsSchedulingWidget::class)
            ->assertSuccessful()
            ->assertDontSee('Выручка')
            ->assertDontSee('USD');
    }

    public function test_custom_period_is_passed_to_all_reactive_analytics_widgets(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        DB::table('bookings')->where('id', $booking->getKey())->update([
            'created_at' => '2026-08-10 10:00:00',
            'updated_at' => '2026-08-10 10:00:00',
        ]);
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)->test(AnalyticsSchedulingWidget::class, [
            'pageFilters' => [
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-08-10',
                'end_date' => '2026-08-10',
            ],
        ]);

        self::assertSame(1, $component->instance()->getData()->bookings);
        self::assertSame('UTC', $organization->defaultTimezone());

        $kpi = Livewire::actingAs($admin)->test(AnalyticsKpiWidget::class, [
            'pageFilters' => [
                'period' => DashboardPeriod::Custom,
                'start_date' => '2026-08-10',
                'end_date' => '2026-08-10',
            ],
        ]);

        self::assertSame(1, $kpi->instance()->getData()['scheduling']->bookings);
    }

    public function test_dashboard_query_count_does_not_grow_with_client_count(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $before = $this->dashboardQueryCount($admin);
        Client::factory()->forOrganization($organization)->count(25);
        $after = $this->dashboardQueryCount($admin);

        self::assertSame($before, $after);
        self::assertLessThanOrEqual(80, $after);
    }

    private function dashboardQueryCount(User $admin): int
    {
        $queryCount = 0;
        $listening = true;
        DB::listen(static function () use (&$queryCount, &$listening): void {
            if ($listening) {
                $queryCount++;
            }
        });

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSuccessful()
            ->set('filters', ['period' => DashboardPeriod::Today]);

        $listening = false;

        return $queryCount;
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();

        return [$organization, $admin];
    }
}
