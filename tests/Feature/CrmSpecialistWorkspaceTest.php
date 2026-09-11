<?php

namespace Tests\Feature;

use App\Filament\Pages\WorkSchedule;
use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\User;
use App\Modules\Analytics\Application\ClientSegmentQuery;
use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientBookingRestriction;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CrmSpecialistWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::create(2026, 9, 10, 8, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_journal_renders_bookings_in_organization_timezone_and_keeps_the_existing_table_available(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('Asia/Almaty');
        $specialist->forceFill(['timezone' => 'Asia/Almaty'])->save();
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, $this->weekdayDefinitions());
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Иван Петров']);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => CarbonImmutable::create(2026, 9, 10, 9, 30, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 9, 10, 10, 30, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 9, 10, 10, 30, 0, 'UTC'),
                'schedule_timezone' => 'Asia/Almaty',
            ]);

        $this->resolveFilamentContext($admin, $organization);
        $component = Livewire::actingAs($admin)->test(ListBookings::class);

        $days = $component->instance()->getJournalDaysProperty();

        self::assertSame('2026-09-07', $component->instance()->weekStart);
        self::assertSame('Иван Петров', $days['2026-09-10']['bookings'][0]['client']);
        self::assertSame('14:30', $days['2026-09-10']['bookings'][0]['start_time']);
        self::assertTrue($days['2026-09-10']['is_working']);
        self::assertFalse($days['2026-09-13']['is_working']);
        self::assertStringNotContainsString('confirmed', json_encode($days, JSON_THROW_ON_ERROR));
        $component->assertSee('Иван Петров')->assertSee('Не работает');
        self::assertTrue($component->instance()->getTableRecords()->contains('id', $booking->id));
    }

    public function test_work_schedule_changes_authoritative_availability_and_preserves_booking_conflicts(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        $this->resolveFilamentContext($admin, $organization);
        $component = Livewire::actingAs($admin)->test(WorkSchedule::class);

        $component
            ->set('selectedWeekdays', [1, 2, 3, 4, 5])
            ->set('startTime', '09:00')
            ->set('endTime', '19:00')
            ->call('saveSchedule')
            ->assertHasNoErrors();

        self::assertSame(5, $specialist->workingHours()->count());

        $availability = app(CalculateAvailability::class)->forStaff(
            $admin,
            $specialist->id,
            $service->id,
            '2026-09-16',
            '2026-09-16',
            VisitFormat::Office,
            'UTC',
        );
        self::assertSame('09:00', $availability->slots[0]->startsAt->format('H:i'));

        $client = Client::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => CarbonImmutable::create(2026, 9, 16, 14, 30, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 9, 16, 15, 30, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 9, 16, 15, 30, 0, 'UTC'),
            ]);

        $component
            ->set('selectedWeekdays', [1, 2, 3, 4, 5])
            ->set('startTime', '12:00')
            ->set('endTime', '14:00')
            ->call('saveSchedule');

        self::assertSame('09:00', substr((string) $specialist->workingHours()->where('weekday', 3)->value('start_time'), 0, 5));
        self::assertNotEmpty($component->instance()->impactBookings);

        $component
            ->set('acknowledgeImpact', true)
            ->call('saveSchedule')
            ->assertHasNoErrors();

        self::assertDatabaseHas('bookings', ['id' => $booking->id]);
        self::assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_work_schedule_restores_a_uniform_break_across_all_selected_days(): void
    {
        [$organization, $admin, $specialist] = $this->fixture('UTC');
        $definitions = [];
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            $definitions[] = ['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '13:00'];
            $definitions[] = ['weekday' => $weekday, 'start_time' => '14:00', 'end_time' => '19:00'];
        }
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, $definitions);

        $this->resolveFilamentContext($admin, $organization);
        $component = Livewire::actingAs($admin)->test(WorkSchedule::class);

        self::assertTrue($component->instance()->breakEnabled);
        self::assertSame('13:00', $component->instance()->breakStart);
        self::assertSame('14:00', $component->instance()->breakEnd);
    }

    public function test_work_schedule_overrides_remove_and_restore_client_slots_without_deleting_bookings(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        $this->resolveFilamentContext($admin, $organization);
        $component = Livewire::actingAs($admin)->test(WorkSchedule::class);
        $component
            ->set('selectedWeekdays', [1, 2, 3, 4, 5])
            ->set('startTime', '09:00')
            ->set('endTime', '19:00')
            ->call('saveSchedule')
            ->call('editDate', '2026-09-14')
            ->set('overrideType', ScheduleExceptionType::DayOff->value)
            ->call('saveOverride')
            ->assertHasNoErrors();

        $dayOff = app(CalculateAvailability::class)->forStaff(
            $admin,
            $specialist->id,
            $service->id,
            '2026-09-14',
            '2026-09-14',
            VisitFormat::Office,
            'UTC',
        );
        self::assertCount(0, $dayOff->slots);

        $component
            ->set('overrideType', 'working')
            ->call('saveOverride')
            ->assertHasNoErrors();

        $restored = app(CalculateAvailability::class)->forStaff(
            $admin,
            $specialist->id,
            $service->id,
            '2026-09-14',
            '2026-09-14',
            VisitFormat::Office,
            'UTC',
        );
        self::assertNotEmpty($restored->slots);

        $component
            ->set('overrideType', ScheduleExceptionType::CustomWindow->value)
            ->set('overrideStart', '12:00')
            ->set('overrideEnd', '17:00')
            ->call('saveOverride')
            ->assertHasNoErrors();

        $custom = app(CalculateAvailability::class)->forStaff(
            $admin,
            $specialist->id,
            $service->id,
            '2026-09-14',
            '2026-09-14',
            VisitFormat::Office,
            'UTC',
        );
        self::assertSame(['12:00', '13:15', '14:30', '15:45'], array_map(
            fn ($slot): string => $slot->startsAt->format('H:i'),
            $custom->slots,
        ));
        self::assertDatabaseHas('schedule_exceptions', [
            'organization_id' => $organization->id,
            'specialist_id' => $specialist->id,
            'exception_date' => '2026-09-14',
            'exception_type' => ScheduleExceptionType::CustomWindow->value,
        ]);
    }

    public function test_client_segments_are_authoritative_tenant_scoped_and_compose_with_search(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC');
        $new = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Новый Иван',
            'created_at' => now()->subDays(10),
        ]);
        $regular = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Постоянный клиент',
            'created_at' => now()->subDays(200),
        ]);
        for ($index = 1; $index <= 3; $index++) {
            $this->completedBooking($organization, $regular, $specialist, $service, now()->subDays(10 + $index));
        }
        $dormant = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Спящий клиент',
            'created_at' => now()->subDays(200),
        ]);
        $this->completedBooking($organization, $dormant, $specialist, $service, now()->subDays(120));
        $restricted = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Ограниченный клиент',
            'created_at' => now()->subDays(200),
        ]);
        ClientBookingRestriction::factory()->forOrganization($organization)->forClient($restricted)->blockedBy($admin)->create([
            'reason' => 'Тестовое ограничение',
            'blocked_at' => now(),
        ]);
        $foreignOrganization = Organization::factory()->create();
        Client::factory()->forOrganization($foreignOrganization)->create(['full_name' => 'Новый Иван']);

        $this->setOrganization($organization);
        $segments = app(ClientSegmentQuery::class);
        $counts = $segments->summary();

        self::assertSame(4, $counts[ClientSegment::All->value]);
        self::assertSame(1, $counts[ClientSegment::NewClients->value]);
        self::assertSame(1, $counts[ClientSegment::Regular->value]);
        self::assertSame(1, $counts[ClientSegment::Dormant->value]);
        self::assertSame(2, $counts[ClientSegment::NoCompletedVisits->value]);
        self::assertSame(1, $counts[ClientSegment::Restricted->value]);

        $search = app(ClientSearch::class);
        $query = $segments->query(ClientSegment::NewClients);
        $search->apply($query, 'Иван');
        self::assertSame([$new->id], $query->pluck('id')->all());
        self::assertNotContains($foreignOrganization->id, $query->pluck('organization_id')->all());
    }

    public function test_client_resource_keeps_search_and_segment_filter_in_the_same_livewire_table(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $matching = Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Иван Сегмент',
            'created_at' => now()->subDays(2),
        ]);
        Client::factory()->forOrganization($organization)->create([
            'full_name' => 'Иван Старый',
            'created_at' => now()->subDays(200),
        ]);

        $this->resolveFilamentContext($admin, $organization);
        $component = Livewire::actingAs($admin)->test(ListClients::class)->assertSuccessful();
        $component->set('tableSearch', 'Иван');
        $component->call('selectSegment', ClientSegment::NewClients->value);

        $component->assertSee('Категории');
        self::assertTrue($component->instance()->getTableRecords()->contains('id', $matching->id));
        self::assertCount(1, $component->instance()->getTableRecords());
    }

    public function test_journal_slot_prefills_existing_booking_creation_flow(): void
    {
        [$organization, $admin, $specialist] = $this->organizationWithAdminAndSpecialist('UTC');
        $this->resolveFilamentContext($admin, $organization);
        $query = [
            'starts_at' => '2026-09-09 14:30',
            'specialist_id' => $specialist->id,
            'return_to_journal' => '1',
            'week' => '2026-09-07',
        ];

        $component = Livewire::withQueryParams($query)
            ->actingAs($admin)
            ->test(CreateBooking::class);

        self::assertSame($specialist->id, (int) $component->instance()->data['specialist_id']);
        self::assertSame('2026-09-09 14:30', CarbonImmutable::parse((string) $component->instance()->data['starts_at'])->format('Y-m-d H:i'));
    }

    private function fixture(string $timezone): array
    {
        [$organization, $admin, $specialist] = $this->organizationWithAdminAndSpecialist($timezone);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 15,
            'formats' => ['office'],
        ]);
        SpecialistServiceAssignment::factory()->create([
            'organization_id' => $organization->id,
            'specialist_id' => $specialist->id,
            'service_id' => $service->id,
        ]);
        $this->enableFeature($organization, OrganizationFeature::ServiceCatalog);

        return [$organization, $admin, $specialist, $service];
    }

    private function organizationWithAdminAndSpecialist(string $timezone = 'UTC'): array
    {
        $organization = Organization::factory()->create(['timezone' => $timezone]);
        $admin = User::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'Специалист теста',
            'timezone' => $timezone,
        ]);
        $this->setOrganization($organization);

        return [$organization, $admin, $specialist];
    }

    private function organizationWithAdmin(): array
    {
        [$organization, $admin] = $this->organizationWithAdminAndSpecialist();
        $this->enableFeature($organization, OrganizationFeature::ClientRecords);

        return [$organization, $admin];
    }

    private function completedBooking(
        Organization $organization,
        Client $client,
        Specialist $specialist,
        Service $service,
        CarbonImmutable|Carbon $startsAt,
    ): Booking {
        $startsAt = CarbonImmutable::instance($startsAt);

        return Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'organization_id' => $organization->id,
                'status' => BookingStatus::Completed->value,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addHour(),
                'blocking_ends_at' => $startsAt->addHour(),
            ]);
    }

    private function weekdayDefinitions(): array
    {
        return array_map(
            static fn (int $weekday): array => [
                'weekday' => $weekday,
                'start_time' => '09:00',
                'end_time' => '19:00',
            ],
            [1, 2, 3, 4, 5],
        );
    }

    private function resolveFilamentContext(User $admin, Organization $organization): void
    {
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->setOrganization($organization);
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);
    }

    private function enableFeature(Organization $organization, OrganizationFeature $feature): void
    {
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => $feature->value,
            'enabled' => true,
        ]);
    }
}
