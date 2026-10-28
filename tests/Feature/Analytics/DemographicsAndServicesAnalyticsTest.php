<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\DemographicsAnalytics;
use App\Modules\Analytics\Application\ServiceVisitAnalytics;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DemographicsAndServicesAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_demographics_percentages_and_completed_service_distribution_are_backend_aggregates(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        Client::factory()->forOrganization($organization)->create([
            'birth_date' => '2015-01-01',
            'gender' => 'FEMALE',
            'country' => 'Казахстан',
            'created_at' => '2026-10-02 10:00:00',
        ]);
        $adult = Client::factory()->forOrganization($organization)->create([
            'birth_date' => '1980-01-01',
            'gender' => 'MALE',
            'country' => 'Казахстан',
            'created_at' => '2026-10-03 10:00:00',
        ]);
        $service = Service::factory()->forOrganization($organization)->create(['name' => 'Синтетический сеанс']);
        Booking::factory()->forOrganization($organization)->forClient($adult)->forSpecialist($specialist)->forService($service)->create([
            'status' => BookingStatus::Completed->value,
            'visit_format' => VisitFormat::Office->value,
            'starts_at' => '2026-10-10 10:00:00',
            'ends_at' => '2026-10-10 11:00:00',
            'blocking_ends_at' => '2026-10-10 11:00:00',
        ]);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );

        $demographics = app(DemographicsAnalytics::class)->handle($admin, $period);
        self::assertSame(2, $demographics->clientCount);
        self::assertSame(['Дети', '45–59'], array_column($demographics->age, 'label'));
        self::assertSame(50.0, $demographics->sex[0]['percent']);
        self::assertSame('Казахстан', $demographics->country[0]['label']);
        self::assertSame(100.0, $demographics->country[0]['percent']);

        $services = app(ServiceVisitAnalytics::class)->handle($admin, $period);
        self::assertSame(1, $services->visitCount);
        self::assertSame('Синтетический сеанс', $services->services[0]['name']);
        self::assertSame(1, $services->services[0]['client_count']);
    }

    /** @return array{0: Organization, 1: User, 2: Specialist} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $specialist];
    }
}
