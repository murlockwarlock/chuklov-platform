<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\AcquisitionAnalytics;
use App\Modules\Analytics\Application\ClientCohortQuery;
use App\Modules\Analytics\Application\Data\ClientCohortFilters;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\DemographicsAnalytics;
use App\Modules\Analytics\Application\ServiceVisitAnalytics;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
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
        $secondSpecialist = Specialist::factory()->forOrganization($organization)->create(['display_name' => 'Второй специалист']);
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
        Booking::factory()->forOrganization($organization)->forClient($adult)->forSpecialist($secondSpecialist)->forService($service)->create([
            'status' => BookingStatus::Completed->value,
            'visit_format' => VisitFormat::Office->value,
            'starts_at' => '2026-10-11 10:00:00',
            'ends_at' => '2026-10-11 11:00:00',
            'blocking_ends_at' => '2026-10-11 11:00:00',
        ]);
        Booking::factory()->forOrganization($organization)->forClient($adult)->forSpecialist($specialist)->forService($service)->create([
            'status' => BookingStatus::Cancelled->value,
            'visit_format' => VisitFormat::Office->value,
            'starts_at' => '2026-10-12 10:00:00',
            'ends_at' => '2026-10-12 11:00:00',
            'blocking_ends_at' => '2026-10-12 11:00:00',
        ]);
        Booking::factory()->forOrganization($organization)->forClient($adult)->forSpecialist($specialist)->forService($service)->create([
            'status' => BookingStatus::NoShow->value,
            'visit_format' => VisitFormat::Office->value,
            'starts_at' => '2026-10-13 10:00:00',
            'ends_at' => '2026-10-13 11:00:00',
            'blocking_ends_at' => '2026-10-13 11:00:00',
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
        self::assertSame(2, $services->visitCount);
        self::assertContains((int) $service->getKey(), array_column($services->services, 'id'));
        self::assertSame('Синтетический сеанс', $services->services[0]['name']);
        self::assertSame(1, $services->services[0]['client_count']);
        self::assertSame(2, $services->services[0]['visit_count']);
        self::assertCount(2, $services->specialists);
        self::assertSame(1, $services->specialists[0]['visit_count']);
        self::assertSame(1, $services->specialists[1]['visit_count']);
    }

    public function test_age_buckets_use_the_organization_timezone_end_date(): void
    {
        [$organization, $admin] = $this->fixture();
        $organization->forceFill(['timezone' => 'America/Los_Angeles'])->save();
        Client::factory()->forOrganization($organization)->create([
            'birth_date' => '2008-11-02',
            'gender' => 'FEMALE',
            'created_at' => '2026-11-01 10:00:00',
        ]);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-11-01', 'end_date' => '2026-11-01'],
            'America/Los_Angeles',
            CarbonImmutable::parse('2026-11-02 00:30:00', 'UTC'),
        );

        $demographics = app(DemographicsAnalytics::class)->handle($admin, $period);

        self::assertSame(1, $demographics->clientCount);
        self::assertSame('Дети', $demographics->age[0]['label']);
    }

    public function test_demographics_uses_the_full_cohort_while_acquisition_remains_period_bound(): void
    {
        [$organization, $admin] = $this->fixture();
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::AnalyticsAgeRanges,
            json_encode([[
                'key' => 'working_age',
                'label' => '30–44',
                'min' => 30,
                'max' => 44,
            ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        $clientA = Client::factory()->forOrganization($organization)->create([
            'birth_date' => '1985-10-31',
            'gender' => 'FEMALE',
            'country' => 'KZ',
            'created_at' => '2025-01-15 10:00:00',
            'updated_at' => '2025-01-15 10:00:00',
        ]);
        $clientB = Client::factory()->forOrganization($organization)->create([
            'birth_date' => '1985-11-01',
            'gender' => 'MALE',
            'country' => 'KZ',
            'created_at' => '2026-10-15 10:00:00',
            'updated_at' => '2026-10-15 10:00:00',
        ]);
        $clientC = Client::factory()->forOrganization($organization)->create([
            'birth_date' => null,
            'gender' => null,
            'country' => 'RU',
            'created_at' => '2024-06-10 10:00:00',
            'updated_at' => '2024-06-10 10:00:00',
        ]);
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create([
            'birth_date' => '1985-10-31',
            'gender' => 'FEMALE',
            'country' => 'KZ',
            'created_at' => '2026-10-15 10:00:00',
        ]);
        app(OrganizationContext::class)->set($organization);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );
        $filters = new ClientCohortFilters(countries: ['KZ', 'RU']);
        $cohort = app(ClientCohortQuery::class);

        self::assertSame(3, $cohort->previewCount($admin, $filters, $period));
        self::assertEqualsCanonicalizing(
            [$clientA->getKey(), $clientB->getKey(), $clientC->getKey()],
            $cohort->for($admin, $filters, $period)->pluck('clients.id')->all(),
        );
        self::assertNotContains($foreignClient->getKey(), $cohort->for($admin, $filters, $period)->pluck('clients.id')->all());

        $demographics = app(DemographicsAnalytics::class)->handle($admin, $period, cohortFilters: $filters);

        self::assertSame(3, $demographics->clientCount);
        $women = $this->distributionRow($demographics->sex, 'Женщины');
        $men = $this->distributionRow($demographics->sex, 'Мужчины');
        $unspecifiedSex = $this->distributionRow($demographics->sex, 'Не указано');
        self::assertSame(1, $women['count']);
        self::assertSame(1, $men['count']);
        self::assertSame(1, $unspecifiedSex['count']);
        self::assertSame(33.33, $women['percent']);
        self::assertSame(33.33, $men['percent']);
        self::assertSame(33.33, $unspecifiedSex['percent']);

        $kazakhstan = $this->distributionRow($demographics->country, 'KZ');
        $russia = $this->distributionRow($demographics->country, 'RU');
        self::assertSame(2, $kazakhstan['count']);
        self::assertSame(1, $russia['count']);
        self::assertSame(66.67, $kazakhstan['percent']);
        self::assertSame(33.33, $russia['percent']);

        $workingAge = $this->distributionRow($demographics->age, '30–44');
        $unspecifiedAge = $this->distributionRow($demographics->age, 'Не указано');
        self::assertSame(2, $workingAge['count']);
        self::assertSame(1, $unspecifiedAge['count']);
        self::assertSame(66.67, $workingAge['percent']);
        self::assertSame(33.33, $unspecifiedAge['percent']);

        $adults = $this->distributionRow($demographics->children, 'Взрослые');
        $unspecifiedChildren = $this->distributionRow($demographics->children, 'Не указано');
        self::assertSame(2, $adults['count']);
        self::assertSame(1, $unspecifiedChildren['count']);
        self::assertSame(66.67, $adults['percent']);
        self::assertSame(33.33, $unspecifiedChildren['percent']);

        self::assertSame(1, app(AcquisitionAnalytics::class)->handle($admin, $period, cohortFilters: $filters)->newClients);
    }

    public function test_demographics_counts_known_countries_from_the_same_cohort_buckets(): void
    {
        [$organization, $admin] = $this->fixture();
        Client::factory()->forOrganization($organization)->create(['country' => 'KZ', 'created_at' => '2026-10-10 10:00:00']);
        Client::factory()->forOrganization($organization)->create(['country' => 'KZ', 'created_at' => '2026-10-11 10:00:00']);
        Client::factory()->forOrganization($organization)->create(['country' => 'RU', 'created_at' => '2026-10-12 10:00:00']);
        $unknownCountryClient = Client::factory()->forOrganization($organization)->create(['country' => '', 'created_at' => '2026-10-13 10:00:00']);
        $foreignOrganization = Organization::factory()->create();
        Client::factory()->forOrganization($foreignOrganization)->create(['country' => 'US', 'created_at' => '2026-10-10 10:00:00']);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );

        $demographics = app(DemographicsAnalytics::class)->handle($admin, $period);

        self::assertSame(4, $demographics->clientCount);
        self::assertSame(2, $demographics->countryCount);
        self::assertSame(2, $this->distributionRow($demographics->country, 'KZ')['count']);
        self::assertSame(1, $this->distributionRow($demographics->country, 'RU')['count']);
        self::assertSame(1, $this->distributionRow($demographics->country, 'Не указана')['count']);
        self::assertSame(50.0, $this->distributionRow($demographics->country, 'KZ')['percent']);
        self::assertSame(25.0, $this->distributionRow($demographics->country, 'RU')['percent']);
        self::assertSame(25.0, $this->distributionRow($demographics->country, 'Не указана')['percent']);

        $kazakhstan = app(DemographicsAnalytics::class)->handle(
            $admin,
            $period,
            cohortFilters: new ClientCohortFilters(countries: ['KZ']),
        );
        self::assertSame(1, $kazakhstan->countryCount);
        self::assertSame(2, $kazakhstan->clientCount);

        $missing = app(DemographicsAnalytics::class)->handle(
            $admin,
            $period,
            cohortFilters: new ClientCohortFilters(clientIds: [(int) $unknownCountryClient->getKey()]),
        );
        self::assertSame(0, $missing->countryCount);
        self::assertSame(1, $this->distributionRow($missing->country, 'Не указана')['count']);

        $empty = app(DemographicsAnalytics::class)->handle(
            $admin,
            $period,
            cohortFilters: new ClientCohortFilters(clientIds: [PHP_INT_MAX]),
        );
        self::assertSame(0, $empty->clientCount);
        self::assertSame(0, $empty->countryCount);
        self::assertSame([], $empty->country);
    }

    public function test_demographics_uses_the_period_end_for_birthday_boundaries(): void
    {
        [$organization, $admin] = $this->fixture();
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::AnalyticsAgeRanges,
            json_encode([
                ['key' => 'thirty_to_forty', 'label' => '30–40', 'min' => 30, 'max' => 40],
                ['key' => 'forty_one_to_forty_four', 'label' => '41–44', 'min' => 41, 'max' => 44],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        Client::factory()->forOrganization($organization)->create([
            'birth_date' => '1985-10-31',
            'created_at' => '2026-10-15 10:00:00',
            'updated_at' => '2026-10-15 10:00:00',
        ]);
        Client::factory()->forOrganization($organization)->create([
            'birth_date' => '1985-11-01',
            'created_at' => '2026-10-15 10:00:00',
            'updated_at' => '2026-10-15 10:00:00',
        ]);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );

        $age = app(DemographicsAnalytics::class)->handle($admin, $period)->age;

        self::assertSame(1, $this->distributionRow($age, '41–44')['count']);
        self::assertSame(1, $this->distributionRow($age, '30–40')['count']);
    }

    /**
     * @param  list<array{label: string, count: int, percent: float}>  $rows
     * @return array{label: string, count: int, percent: float}
     */
    private function distributionRow(array $rows, string $label): array
    {
        foreach ($rows as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }

        self::fail('Distribution row not found: '.$label);
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
