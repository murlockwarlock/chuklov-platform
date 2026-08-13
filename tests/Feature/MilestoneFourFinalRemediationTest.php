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
use App\Modules\Scheduling\Domain\ValueObjects\SpecialistScheduleDefinition;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
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
}
