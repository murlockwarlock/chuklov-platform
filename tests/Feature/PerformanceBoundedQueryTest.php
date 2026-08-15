<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\DTOs\UpdateMedicalProfileCommand;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Application\UpdateMedicalProfile;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\BookingNeedsAttention;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\BookingSource;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\PaymentStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class PerformanceBoundedQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 27, 12, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_client_list_query_families_do_not_grow_linearly_with_row_count(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $this->resolveFilamentContext($admin, $organization);

        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);

        // Create 3 clients
        Client::factory()->count(3)->forOrganization($organization)->create(['timezone' => 'UTC']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(ListClients::class)
            ->assertSuccessful();

        $smallQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $smallFeatureFlagsCount = count(array_filter($smallQueries, fn (array $q) => str_contains($q['query'], 'organization_feature_flags')));

        // Add 15 more clients (total 18)
        Client::factory()->count(15)->forOrganization($organization)->create(['timezone' => 'UTC']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(ListClients::class)
            ->assertSuccessful();

        $largeQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $largeFeatureFlagsCount = count(array_filter($largeQueries, fn (array $q) => str_contains($q['query'], 'organization_feature_flags')));

        // Feature flags query family must be memoized and execute at most 1 time, never 18 times
        self::assertLessThanOrEqual(1, $largeFeatureFlagsCount, 'Feature flags query repeated once per row, indicating an N+1 leak in policy checks.');
    }

    public function test_booking_list_query_families_do_not_grow_linearly_with_row_count(): void
    {
        [$organization, $admin, $specialist, $service] = $this->schedulingFixture();
        $this->resolveFilamentContext($admin, $organization);

        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);

        // Create 2 clients and 2 bookings
        $clients = Client::factory()->count(2)->forOrganization($organization)->create(['timezone' => 'UTC']);
        foreach ($clients as $index => $client) {
            $this->createBooking($organization, $client, $specialist, $service, CarbonImmutable::create(2026, 4, 6, 9 + $index, 0, 0, 'UTC'));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(ListBookings::class)
            ->assertSuccessful();

        $smallQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $smallEligibilityCount = count(array_filter($smallQueries, fn (array $q) => str_contains($q['query'], 'specialist_service_assignments')));
        $smallSettingsCount = count(array_filter($smallQueries, fn (array $q) => str_contains($q['query'], 'organization_settings')));

        // Create 8 more clients and bookings (total 10)
        $moreClients = Client::factory()->count(8)->forOrganization($organization)->create(['timezone' => 'UTC']);
        foreach ($moreClients as $index => $client) {
            $this->createBooking($organization, $client, $specialist, $service, CarbonImmutable::create(2026, 4, 13, 9 + $index, 0, 0, 'UTC'));
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(ListBookings::class)
            ->assertSuccessful();

        $largeQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $largeSettingsCount = count(array_filter($largeQueries, fn (array $q) => str_contains($q['query'], 'organization_settings')));
        $largeOrgCount = count(array_filter($largeQueries, fn (array $q) => str_contains($q['query'], '"organizations"')));

        // Settings and organization queries must be memoized/context-derived and not repeat 10 times
        self::assertLessThanOrEqual(2, $largeSettingsCount, 'Organization settings queries repeated once per row.');
        self::assertLessThanOrEqual(2, $largeOrgCount, 'Organization queries repeated once per row.');
    }

    public function test_client_view_infolist_executes_single_medical_profile_read(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $this->resolveFilamentContext($admin, $organization);

        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);

        app(UpdateMedicalProfile::class)->handle($admin, $client, new UpdateMedicalProfileCommand(
            anamnesis: 'Test anamnesis',
            complaintsGoals: 'Test goals',
            operationsInjuries: 'Test operations',
            medicines: 'Test meds',
            supplements: 'Test supps',
        ));

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(ViewClient::class, ['record' => $client->getKey()])
            ->assertSuccessful();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $medicalProfileQueries = array_filter(
            $queries,
            fn (array $query): bool => str_contains($query['query'], 'medical_profiles')
        );

        // Even though infolist has 5 separate medical text entries, medical_profiles table must be queried at most once
        self::assertLessThanOrEqual(1, count($medicalProfileQueries), 'medical_profiles table was queried repeatedly during single infolist render.');
    }

    public function test_medical_profile_update_invalidates_request_scoped_cache(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $this->resolveFilamentContext($admin, $organization);

        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);

        $updateService = app(UpdateMedicalProfile::class);
        $getService = app(GetMedicalProfile::class);

        $updateService->handle($admin, $client, new UpdateMedicalProfileCommand(
            anamnesis: 'Initial Anamnesis',
            complaintsGoals: null,
            operationsInjuries: null,
            medicines: null,
            supplements: null,
        ));

        $firstRead = $getService->handle($admin, $client);
        self::assertSame('Initial Anamnesis', $firstRead?->anamnesis);

        // Update profile in the same request lifecycle
        $updateService->handle($admin, $client, new UpdateMedicalProfileCommand(
            anamnesis: 'Updated Anamnesis',
            complaintsGoals: null,
            operationsInjuries: null,
            medicines: null,
            supplements: null,
        ));

        // GetMedicalProfile must return the updated value, not stale cached initial value
        $secondRead = $getService->handle($admin, $client);
        self::assertSame('Updated Anamnesis', $secondRead?->anamnesis);
    }

    public function test_adversarial_scheduling_availability_calculations_remain_isolated_across_distinct_bookings(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $this->resolveFilamentContext($admin, $organization);

        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);

        $specialistA = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialistB = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);

        $serviceA = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'formats' => ['office'],
        ]);
        $serviceB = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 90,
            'buffer_minutes' => 15,
            'formats' => ['office'],
        ]);

        app(AssignSpecialistToService::class)->handle($admin, $specialistA, $serviceA);
        app(AssignSpecialistToService::class)->handle($admin, $specialistB, $serviceB);

        // Specialist A works Mondays 09:00 - 12:00
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialistA, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]]);

        // Specialist B works Mondays 14:00 - 18:00
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialistB, [[
            'weekday' => 1,
            'start_time' => '14:00',
            'end_time' => '18:00',
        ]]);

        $clientA = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $clientB = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);

        // Booking A: Specialist A on Monday 09:00 (aligned with Specialist A working hours)
        $bookingA = $this->createBooking($organization, $clientA, $specialistA, $serviceA, CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'));

        // Booking B: Specialist B on Monday 09:00 (NOT aligned with Specialist B working hours, which start at 14:00)
        $bookingB = $this->createBooking($organization, $clientB, $specialistB, $serviceB, CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'));

        $needsAttention = app(BookingNeedsAttention::class);

        // Booking A should NOT need attention (it is aligned)
        self::assertFalse($needsAttention->handle($bookingA), 'Booking A was unexpectedly flagged as needing attention.');

        // Booking B SHOULD need attention (it is misaligned with Specialist B working hours)
        self::assertTrue($needsAttention->handle($bookingB), 'Booking B was not flagged as needing attention.');
    }

    /** @return array{Organization, User, Specialist, Service} */
    private function schedulingFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 15,
            'formats' => ['office'],
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]]);

        return [$organization, $admin, $specialist, $service];
    }

    private function createBooking(
        Organization $organization,
        Client $client,
        Specialist $specialist,
        Service $service,
        CarbonImmutable $startsAt,
    ): Booking {
        $endsAt = $startsAt->addMinutes($service->durationMinutes());
        $blockingEndsAt = $endsAt->addMinutes($service->buffer_minutes);

        $booking = new Booking;
        $booking->forceFill([
            'calendar_uid' => (string) Str::uuid(),
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'specialist_id' => $specialist->getKey(),
            'service_id' => $service->getKey(),
            'status' => BookingStatus::Confirmed->value,
            'visit_format' => VisitFormat::Office->value,
            'payment_status' => PaymentStatus::Unpaid->value,
            'payment_requirement' => null,
            'source' => BookingSource::Crm->value,
            'party_size' => 1,
            'schedule_timezone' => 'UTC',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'blocking_ends_at' => $blockingEndsAt,
        ]);
        $booking->save();

        return $booking;
    }

    private function resolveFilamentContext(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(OrganizationContext::class)->set($organization);
    }
}
