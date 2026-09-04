<?php

namespace Tests\Feature;

use App\Filament\Pages\SchedulingConfiguration;
use App\Filament\Resources\ScheduleExceptions\Pages\ListScheduleExceptions;
use App\Filament\Resources\Specialists\Pages\ListSpecialists;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Application\CreateBooking;
use App\Modules\Scheduling\Application\CreateScheduleException;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Scheduling\Domain\ValueObjects\SpecialistScheduleDefinition;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\ScenarioNotificationSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class MilestoneFourFinalRemediationTest extends TestCase
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

    public function test_stale_acknowledgement_is_rejected_when_the_current_impact_becomes_empty(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = $this->createBooking($client, $specialist, $service, 'empty-impact');

        try {
            app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
                'weekday' => 1,
                'start_time' => '10:00',
                'end_time' => '17:00',
            ]]);
            self::fail('The first schedule mutation did not produce an impact preview.');
        } catch (ValidationException $exception) {
            $digest = (string) $exception->errors()['schedule_impact_digest'][0];
        }

        $booking->forceFill(['status' => BookingStatus::Cancelled])->save();

        try {
            app(SetSpecialistWorkingHours::class)->handle(
                $admin,
                $specialist,
                [[
                    'weekday' => 1,
                    'start_time' => '10:00',
                    'end_time' => '17:00',
                ]],
                true,
                $digest,
            );
            self::fail('A stale non-empty acknowledgement was accepted after the impact set became empty.');
        } catch (ValidationException $exception) {
            self::assertSame('', $exception->errors()['schedule_impact_digest'][0]);
            self::assertSame('[]', $exception->errors()['schedule_impact_bookings'][0]);
        }

        self::assertSame('09:00', substr((string) $specialist->workingHours()->firstOrFail()->start_time, 0, 5));
        self::assertSame($organization->getKey(), $booking->fresh()->organization_id);
    }

    public function test_filament_schedule_configuration_shows_and_retains_the_impact_preview(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->createBooking($client, $specialist, $service, 'filament-preview');
        $impact = app(ScheduleMutationImpactCalculator::class)->forWorkingHours(
            $specialist,
            SpecialistScheduleDefinition::from([
                ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '17:00'],
            ]),
        );
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(SchedulingConfiguration::class)
            ->fillForm([
                'specialist_id' => $specialist->getKey(),
                'lead_time_minutes' => 0,
                'cancellation_cutoff_minutes' => 0,
                'office_location' => null,
                'working_hours' => [[
                    'weekday' => 1,
                    'start_time' => '10:00',
                    'end_time' => '17:00',
                ]],
                'acknowledge_impact' => false,
                'impact_digest' => null,
            ])
            ->call('save')
            ->assertHasErrors('schedule_impact')
            ->assertSet('data.impact_digest', $impact->digest)
            ->assertSet('data.schedule_impact_bookings.0.id', $impact->bookingIds[0]);

        $component
            ->assertSee($client->full_name)
            ->set('data.acknowledge_impact', true)
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame('10:00', substr((string) $specialist->workingHours()->firstOrFail()->start_time, 0, 5));
    }

    public function test_filament_preview_refreshes_when_the_affected_booking_set_changes(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $booking = $this->createBooking($client, $specialist, $service, 'filament-refresh');
        $this->resolveFilamentContext($admin, $organization);
        $payload = [
            'specialist_id' => $specialist->getKey(),
            'lead_time_minutes' => 0,
            'cancellation_cutoff_minutes' => 0,
            'office_location' => null,
            'working_hours' => [[
                'weekday' => 1,
                'start_time' => '10:00',
                'end_time' => '17:00',
            ]],
            'acknowledge_impact' => false,
            'impact_digest' => null,
        ];
        $component = Livewire::actingAs($admin)
            ->test(SchedulingConfiguration::class)
            ->fillForm($payload)
            ->call('save')
            ->assertHasErrors('schedule_impact');
        $oldDigest = $component->instance()->data['impact_digest'];

        $booking->forceFill(['event_version' => 2])->save();
        $newImpact = app(ScheduleMutationImpactCalculator::class)->forWorkingHours(
            $specialist,
            SpecialistScheduleDefinition::from([
                ['weekday' => 1, 'start_time' => '10:00', 'end_time' => '17:00'],
            ]),
        );
        self::assertNotSame($oldDigest, $newImpact->digest);

        $component
            ->set('data.impact_digest', $oldDigest)
            ->set('data.acknowledge_impact', true)
            ->call('save')
            ->assertHasErrors('schedule_impact')
            ->assertSet('data.impact_digest', $newImpact->digest)
            ->assertSet('data.schedule_impact_bookings.0.id', $booking->getKey());
    }

    public function test_unrelated_scheduling_save_does_not_clear_existing_weekly_hours(): void
    {
        [$organization, $admin, , $specialist] = $this->fixture();
        $weeklySchedule = array_map(
            static fn (int $weekday): array => [
                'weekday' => $weekday,
                'start_time' => '09:00',
                'end_time' => '17:00',
            ],
            range(1, 7),
        );
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, $weeklySchedule);
        $before = $this->workingHourSnapshot($organization->getKey(), $specialist->getKey());
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(SchedulingConfiguration::class)
            ->fillForm([
                'lead_time_minutes' => 15,
                'working_hours' => [],
                'clear_working_hours' => false,
            ])
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame($before, $this->workingHourSnapshot($organization->getKey(), $specialist->getKey()));
    }

    public function test_viewer_timezone_uses_the_staff_linked_specialist_on_mount_save_and_reload(): void
    {
        [$organization, $admin, , $specialist] = $this->fixture();
        $specialist->forceFill([
            'staff_user_id' => $admin->getKey(),
            'viewer_timezone' => null,
            'viewer_timezone_source' => 'organization',
        ])->save();
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(SchedulingConfiguration::class)
            ->assertSet('data.specialist_id', $specialist->getKey())
            ->assertSet('data.viewer_timezone', null)
            ->set('data.viewer_timezone', 'Asia/Bangkok')
            ->set('data.working_hours', [])
            ->set('data.clear_working_hours', false)
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame('Asia/Bangkok', $specialist->fresh()->viewer_timezone);
        self::assertSame('manual', $specialist->fresh()->viewer_timezone_source);

        Livewire::actingAs($admin)
            ->test(SchedulingConfiguration::class)
            ->assertSet('data.specialist_id', $specialist->getKey())
            ->assertSet('data.viewer_timezone', 'Asia/Bangkok');
    }

    public function test_switching_specialists_loads_each_schedule_without_cross_overwrite(): void
    {
        [$organization, $admin, , $specialist] = $this->fixture();
        $secondSpecialist = Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'Second Specialist',
        ]);
        app(SetSpecialistWorkingHours::class)->handle($admin, $secondSpecialist, [[
            'weekday' => 2,
            'start_time' => '13:00',
            'end_time' => '18:00',
        ]]);
        $specialist->forceFill(['staff_user_id' => $admin->getKey()])->save();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(SchedulingConfiguration::class);
        $component->assertSet('data.specialist_id', $specialist->getKey());
        self::assertSame(1, (int) array_values($component->instance()->data['working_hours'])[0]['weekday']);

        $component->set('data.specialist_id', $secondSpecialist->getKey());
        self::assertSame(2, (int) array_values($component->instance()->data['working_hours'])[0]['weekday']);
        self::assertSame('13:00', array_values($component->instance()->data['working_hours'])[0]['start_time']);

        $component->set('data.specialist_id', $specialist->getKey());
        self::assertSame(1, (int) array_values($component->instance()->data['working_hours'])[0]['weekday']);
        self::assertSame('09:00', array_values($component->instance()->data['working_hours'])[0]['start_time']);

        self::assertSame(1, $specialist->workingHours()->count());
        self::assertSame(1, $secondSpecialist->workingHours()->count());
    }

    public function test_explicit_clear_requires_the_clear_schedule_control(): void
    {
        [$organization, $admin, , $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);
        $component = Livewire::actingAs($admin)
            ->test(SchedulingConfiguration::class)
            ->fillForm([
                'specialist_id' => $specialist->getKey(),
                'working_hours' => [],
                'clear_working_hours' => false,
            ])
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(1, $specialist->workingHours()->count());

        $component
            ->set('data.clear_working_hours', true)
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame(0, $specialist->workingHours()->count());
    }

    public function test_staging_seeders_do_not_change_specialist_timezone_or_working_hours(): void
    {
        [$organization, , , $specialist] = $this->fixture();
        $specialist->forceFill([
            'viewer_timezone' => 'Asia/Bangkok',
            'viewer_timezone_source' => 'manual',
        ])->save();
        $before = $this->workingHourSnapshot($organization->getKey(), $specialist->getKey());

        app(LegalDocumentSeeder::class)->run();
        app(ScenarioNotificationSeeder::class)->run();

        $specialist->refresh();
        self::assertSame('Asia/Bangkok', $specialist->viewer_timezone);
        self::assertSame('manual', $specialist->viewer_timezone_source);
        self::assertSame($before, $this->workingHourSnapshot($organization->getKey(), $specialist->getKey()));
    }

    public function test_quick_specialist_deactivation_uses_the_visible_impact_handoff(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->createBooking($client, $specialist, $service, 'quick-deactivate');
        $impact = app(ScheduleMutationImpactCalculator::class)->forSpecialistChange(
            $specialist,
            false,
            $specialist->timezone,
        );
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(ListSpecialists::class)
            ->mountTableAction('deactivate', $specialist->getKey())
            ->assertTableActionDataSet(['impact_digest' => $impact->digest])
            ->assertSchemaComponentVisible('schedule_impact_preview');

        $component
            ->setTableActionData(['acknowledge_impact' => true])
            ->callMountedTableAction()
            ->assertHasNoErrors();

        self::assertFalse((bool) $specialist->fresh()->is_active);
    }

    public function test_schedule_exception_deletion_uses_the_visible_impact_handoff(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '13:00',
            'end_time' => '17:00',
        ]]);
        $exception = app(CreateScheduleException::class)->handle($admin, $specialist, [
            'exception_date' => '2026-04-06',
            'exception_type' => ScheduleExceptionType::CustomWindow->value,
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]);
        $booking = $this->createBooking($client, $specialist, $service, 'exception-preview');
        $impact = app(ScheduleMutationImpactCalculator::class)->forExceptionDeletion($specialist, $exception);
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(ListScheduleExceptions::class)
            ->mountTableAction('delete', $exception->getKey())
            ->assertTableActionDataSet(['impact_digest' => $impact->digest])
            ->assertSchemaComponentVisible('schedule_impact_preview')
            ->setTableActionData(['acknowledge_impact' => true])
            ->callMountedTableAction()
            ->assertHasNoErrors();

        self::assertModelMissing($exception);
        self::assertModelExists($booking->fresh());
    }

    public function test_display_local_availability_accepts_dates_with_midnight_transitions(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 1, 1, 12, 0, 0, 'UTC'));

        foreach (self::midnightTransitionDates() as $case) {
            [$timezone, $date] = $case;
            [$organization, $admin, $client, $specialist, $service] = $this->fixture(
                specialistTimezone: 'UTC',
                clientTimezone: $timezone,
            );
            $weekday = CarbonImmutable::parse($date, 'UTC')->dayOfWeekIso;
            app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
                'weekday' => $weekday,
                'start_time' => '09:00',
                'end_time' => '12:00',
            ]]);

            $result = app(CalculateAvailability::class)->forClient(
                client: $client,
                specialistId: $specialist->getKey(),
                serviceId: $service->getKey(),
                dateFrom: $date,
                dateTo: $date,
                format: VisitFormat::Office,
            );

            self::assertNotEmpty($result->slots, $timezone.' '.$date);
            foreach ($result->slots as $slot) {
                self::assertSame($date, $slot->startsAt->setTimezone($timezone)->toDateString());
            }
            self::assertSame($timezone, $result->displayTimezone);
            self::assertSame($organization->getKey(), $client->organization_id);
        }
    }

    /** @return array<string, array{string, string}> */
    public static function midnightTransitionDates(): array
    {
        return [
            'cairo midnight gap' => ['Africa/Cairo', '2026-04-24'],
            'havana midnight transition' => ['America/Havana', '2026-03-08'],
            'santiago midnight transition' => ['America/Santiago', '2026-09-06'],
        ];
    }

    /** @return array{Organization, User, Client, Specialist, Service} */
    private function fixture(
        string $specialistTimezone = 'UTC',
        string $clientTimezone = 'UTC',
        array $formats = ['office', 'online'],
    ): array {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => $clientTimezone]);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => $specialistTimezone]);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 15,
            'formats' => $formats,
        ]);
        $this->setOrganization($organization);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]]);

        return [$organization, $admin, $client, $specialist, $service];
    }

    private function createBooking(Client $client, Specialist $specialist, Service $service, string $key): Booking
    {
        return app(CreateBooking::class)->handle(
            actor: $client,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: $key,
        );
    }

    private function resolveFilamentContext(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(OrganizationContext::class)->set($organization);
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    /** @return list<array{weekday: int, start_time: string, end_time: string, is_active: bool}> */
    private function workingHourSnapshot(int $organizationId, int $specialistId): array
    {
        return SpecialistWorkingHour::query()
            ->where('organization_id', $organizationId)
            ->where('specialist_id', $specialistId)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get()
            ->map(static fn (SpecialistWorkingHour $hour): array => [
                'weekday' => (int) $hour->weekday,
                'start_time' => substr((string) $hour->start_time, 0, 5),
                'end_time' => substr((string) $hour->end_time, 0, 5),
                'is_active' => (bool) $hour->is_active,
            ])
            ->values()
            ->all();
    }
}
