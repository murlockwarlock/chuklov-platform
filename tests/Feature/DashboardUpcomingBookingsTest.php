<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\UpcomingBookingsWidget;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\GetDashboardUpcomingBookings;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class DashboardUpcomingBookingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_bookings_are_organization_scoped_and_tenant_isolated(): void
    {
        [$orgA, $adminA] = $this->setupOrganizationWithAdmin('Europe/Moscow');
        [$orgB, $adminB] = $this->setupOrganizationWithAdmin('Europe/Moscow');

        $now = CarbonImmutable::now('Europe/Moscow')->startOfDay()->addHours(10);

        // Booking in Org A
        $this->createBookingForOrg($orgA, $now, 'Client Org A');

        // Booking in Org B
        $this->createBookingForOrg($orgB, $now, 'Client Org B');

        app(OrganizationContext::class)->set($orgA);
        $action = app(GetDashboardUpcomingBookings::class);
        $resultA = $action->handle($adminA);

        self::assertSame(1, $resultA->todayCount);
        self::assertCount(1, $resultA->days[0]->bookings);
        self::assertSame('Client Org A', $resultA->days[0]->bookings->first()->client->full_name);

        // Now check Org B
        app(OrganizationContext::class)->set($orgB);
        $resultB = $action->handle($adminB);

        self::assertSame(1, $resultB->todayCount);
        self::assertCount(1, $resultB->days[0]->bookings);
        self::assertSame('Client Org B', $resultB->days[0]->bookings->first()->client->full_name);
    }

    public function test_actor_without_membership_in_organization_is_denied(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'Europe/Moscow']);
        $unrelatedUser = User::factory()->create();
        app(OrganizationContext::class)->set($organization);

        $this->expectException(AuthorizationException::class);
        app(GetDashboardUpcomingBookings::class)->handle($unrelatedUser);
    }

    public function test_bookings_use_organization_timezone_for_day_boundaries(): void
    {
        // Organization with Asia/Tokyo (UTC+9)
        [$org, $admin] = $this->setupOrganizationWithAdmin('Asia/Tokyo');
        app(OrganizationContext::class)->set($org);

        $nowTokyo = CarbonImmutable::now('Asia/Tokyo')->startOfDay();

        // 23:30 Tokyo time on Day 0 (today)
        $this->createBookingForOrg($org, $nowTokyo->addHours(23)->addMinutes(30), 'Late Today');

        // 00:30 Tokyo time on Day 1 (tomorrow)
        $this->createBookingForOrg($org, $nowTokyo->addDay()->addMinutes(30), 'Early Tomorrow');

        $result = app(GetDashboardUpcomingBookings::class)->handle($admin);

        self::assertSame(1, $result->todayCount);
        self::assertSame(1, $result->tomorrowCount);
        self::assertSame('Late Today', $result->days[0]->bookings->first()->client->full_name);
        self::assertSame('Early Tomorrow', $result->days[1]->bookings->first()->client->full_name);
    }

    public function test_results_are_bounded_to_ten_per_day_and_counts_are_untruncated(): void
    {
        [$org, $admin] = $this->setupOrganizationWithAdmin('Europe/Moscow');
        app(OrganizationContext::class)->set($org);

        $now = CarbonImmutable::now('Europe/Moscow')->startOfDay();

        // Create 15 bookings for today
        for ($i = 0; $i < 15; $i++) {
            $this->createBookingForOrg($org, $now->addHours(8)->addMinutes($i * 30), "Client {$i}");
        }

        $result = app(GetDashboardUpcomingBookings::class)->handle($admin);

        // True count should be 15
        self::assertSame(15, $result->todayCount);
        self::assertSame(15, $result->days[0]->totalCount);
        // Bounded collection should contain exactly 10
        self::assertCount(10, $result->days[0]->bookings);
    }

    public function test_cancelled_rejected_and_noshow_bookings_are_excluded(): void
    {
        [$org, $admin] = $this->setupOrganizationWithAdmin('Europe/Moscow');
        app(OrganizationContext::class)->set($org);

        $now = CarbonImmutable::now('Europe/Moscow')->startOfDay()->addHours(10);

        $this->createBookingForOrg($org, $now, 'Confirmed Client', BookingStatus::Confirmed);
        $this->createBookingForOrg($org, $now->addHour(), 'Cancelled Client', BookingStatus::Cancelled);
        $this->createBookingForOrg($org, $now->addHours(2), 'Rejected Client', BookingStatus::Rejected);
        $this->createBookingForOrg($org, $now->addHours(3), 'NoShow Client', BookingStatus::NoShow);

        $result = app(GetDashboardUpcomingBookings::class)->handle($admin);

        self::assertSame(1, $result->todayCount);
        self::assertCount(1, $result->days[0]->bookings);
        self::assertSame('Confirmed Client', $result->days[0]->bookings->first()->client->full_name);
    }

    public function test_dashboard_page_has_one_column_and_excludes_account_widget(): void
    {
        $dashboard = new Dashboard;

        self::assertSame(1, $dashboard->getColumns());
        self::assertSame(UpcomingBookingsWidget::class, $dashboard->getWidgets()[0]);
        self::assertContains(UpcomingBookingsWidget::class, $dashboard->getWidgets());
        self::assertNotContains(AccountWidget::class, $dashboard->getWidgets());
    }

    public function test_dashboard_widget_renders_full_status_and_header(): void
    {
        [$org, $admin] = $this->setupOrganizationWithAdmin('Europe/Moscow');
        app(OrganizationContext::class)->set($org);

        $now = CarbonImmutable::now('Europe/Moscow')->startOfDay()->addHours(10);
        $this->createBookingForOrg($org, $now, 'Евгений Пронин', BookingStatus::Requested);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        Livewire::test(UpcomingBookingsWidget::class)
            ->assertSuccessful()
            ->assertSee('Ближайшие записи')
            ->assertSee('Евгений Пронин')
            ->assertSee('Ожидает подтверждения')
            ->assertSee('Сегодня:');
    }

    /** @return array{0: Organization, 1: User} */
    private function setupOrganizationWithAdmin(string $timezone): array
    {
        $organization = Organization::factory()->create([
            'timezone' => $timezone,
        ]);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();

        return [$organization, $admin];
    }

    private function createBookingForOrg(
        Organization $organization,
        CarbonImmutable $startsAt,
        string $clientName,
        BookingStatus $status = BookingStatus::Confirmed,
    ): Booking {
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => $clientName]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();

        return Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => $startsAt->setTimezone('UTC'),
                'ends_at' => $startsAt->addHour()->setTimezone('UTC'),
                'blocking_ends_at' => $startsAt->addHour()->setTimezone('UTC'),
                'status' => $status,
                'visit_format' => VisitFormat::Office,
            ]);
    }
}
