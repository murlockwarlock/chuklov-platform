<?php

namespace Tests\Feature\Analytics;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AnalyticsAcquisitionWidget;
use App\Filament\Widgets\AnalyticsAiFailuresWidget;
use App\Filament\Widgets\AnalyticsFinanceWidget;
use App\Filament\Widgets\AnalyticsIngestionFailuresWidget;
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
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

final class AnalyticsDashboardWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_keeps_upcoming_bookings_and_registers_one_shared_filter(): void
    {
        $dashboard = new Dashboard;

        self::assertSame(1, $dashboard->getColumns());
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

    public function test_analytics_widgets_render_without_replacing_upcoming_widget(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(AnalyticsAcquisitionWidget::class)
            ->assertSuccessful()
            ->assertSee('Привлечение')
            ->assertSee('Новые клиенты');
        Livewire::actingAs($admin)
            ->test(AnalyticsSchedulingWidget::class)
            ->assertSuccessful()
            ->assertSee('Записи и визиты')
            ->assertSee('Завершённые визиты');
        Livewire::actingAs($admin)
            ->test(AnalyticsFinanceWidget::class)
            ->assertSuccessful()
            ->assertSee('Финансы')
            ->assertSee('Расчёт недоступен');
        Livewire::actingAs($admin)
            ->test(AnalyticsAiFailuresWidget::class)
            ->assertSuccessful()
            ->assertSee('Ошибки запусков ИИ');
        Livewire::actingAs($admin)
            ->test(AnalyticsIngestionFailuresWidget::class)
            ->assertSuccessful()
            ->assertSee('Ошибки обработки знаний');
        Livewire::actingAs($admin)
            ->test(UpcomingBookingsWidget::class)
            ->assertSuccessful()
            ->assertSee('Ближайшие записи');
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
