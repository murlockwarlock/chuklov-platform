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
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_analytics_console_reports_a_dimension_error_without_rendering_partial_result_shape(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $authorizer = Mockery::mock(OrganizationAuthorizer::class, [app(OrganizationContext::class)])->makePartial();
        $authorizer->shouldReceive('allows')->andReturn(true);
        $authorizer->shouldReceive('authorize')->andThrow(new AuthorizationException);
        $this->app->instance(OrganizationAuthorizer::class, $authorizer);

        $component = Livewire::actingAs($admin)
            ->test(AnalyticsConsole::class)
            ->fillForm([
                'dimensions' => ['scheduling'],
                'period' => DashboardPeriod::Today,
            ])
            ->call('analyze')
            ->assertNotified('Анализ обновлён')
            ->assertSee('Некоторые показатели недоступны')
            ->assertSee('Записи и визиты')
            ->assertSee('Не удалось получить результаты выбранных показателей');

        self::assertSame([], $component->instance()->results);
        self::assertSame(['scheduling' => 'Записи и визиты'], $component->instance()->dimensionErrors);
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
