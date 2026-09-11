<?php

namespace Tests\Feature;

use App\Filament\Pages\SchedulingConfiguration;
use App\Filament\Pages\WorkSchedule;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\CalculateAvailability;
use App\Modules\Scheduling\Application\GetScheduleCalendar;
use App\Modules\Scheduling\Application\SetScheduleExceptionSet;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\ScheduleExceptionType;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Scheduling\Domain\Models\SpecialistWorkingHour;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class SchedulingJournalProductionPassTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurring_validity_controls_calendar_and_availability_without_losing_intervals(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [
            [
                'weekday' => 1,
                'start_time' => '09:00',
                'end_time' => '14:00',
                'starts_on' => '2026-10-01',
                'ends_on' => '2026-10-31',
            ],
            [
                'weekday' => 1,
                'start_time' => '18:00',
                'end_time' => '20:00',
                'starts_on' => '2026-10-01',
                'ends_on' => '2026-10-31',
            ],
        ]);

        $calendar = app(GetScheduleCalendar::class)->forSpecialist(
            specialist: $specialist,
            dateFrom: '2026-09-28',
            dateTo: '2026-11-02',
            displayTimezone: 'UTC',
        );

        self::assertFalse($calendar['2026-09-28']['is_working']);
        self::assertSame([
            ['start' => '09:00', 'end' => '14:00', 'start_minutes' => 540, 'end_minutes' => 840],
            ['start' => '18:00', 'end' => '20:00', 'start_minutes' => 1080, 'end_minutes' => 1200],
        ], $calendar['2026-10-05']['intervals']);
        self::assertFalse($calendar['2026-11-02']['is_working']);

        self::assertCount(0, app(CalculateAvailability::class)->forStaff(
            actor: $admin,
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            dateFrom: '2026-09-28',
            dateTo: '2026-09-28',
            format: VisitFormat::Online,
            displayTimezone: 'UTC',
        )->slots);
        self::assertCount(0, app(CalculateAvailability::class)->forStaff(
            actor: $admin,
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            dateFrom: '2026-11-02',
            dateTo: '2026-11-02',
            format: VisitFormat::Online,
            displayTimezone: 'UTC',
        )->slots);

        $availability = app(CalculateAvailability::class)->forStaff(
            actor: $admin,
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            dateFrom: '2026-10-05',
            dateTo: '2026-10-05',
            format: VisitFormat::Online,
            displayTimezone: 'UTC',
        );

        self::assertSame(['09:00', '10:15', '11:30', '12:45', '18:00'], array_map(
            static fn ($slot): string => $slot->startsAt->format('H:i'),
            $availability->slots,
        ));
    }

    public function test_settings_save_round_trips_multiple_intervals_and_validity_dates(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(SchedulingConfiguration::class)
            ->fillForm([
                'specialist_id' => $specialist->getKey(),
                'working_hours' => [
                    [
                        'weekday' => 5,
                        'start_time' => '09:00',
                        'end_time' => '12:00',
                        'starts_on' => '2026-09-01',
                        'ends_on' => null,
                    ],
                    [
                        'weekday' => 5,
                        'start_time' => '14:00',
                        'end_time' => '16:00',
                        'starts_on' => '2026-09-01',
                        'ends_on' => null,
                    ],
                    [
                        'weekday' => 5,
                        'start_time' => '18:00',
                        'end_time' => '20:00',
                        'starts_on' => '2026-09-01',
                        'ends_on' => null,
                    ],
                ],
                'clear_working_hours' => false,
            ])
            ->call('save')
            ->assertHasNoErrors();

        self::assertSame([
            ['weekday' => 5, 'start_time' => '09:00', 'end_time' => '12:00', 'starts_on' => '2026-09-01', 'ends_on' => null],
            ['weekday' => 5, 'start_time' => '14:00', 'end_time' => '16:00', 'starts_on' => '2026-09-01', 'ends_on' => null],
            ['weekday' => 5, 'start_time' => '18:00', 'end_time' => '20:00', 'starts_on' => '2026-09-01', 'ends_on' => null],
        ], SpecialistWorkingHour::query()
            ->where('organization_id', $organization->getKey())
            ->where('specialist_id', $specialist->getKey())
            ->orderBy('start_time')
            ->get()
            ->map(static fn (SpecialistWorkingHour $hour): array => [
                'weekday' => (int) $hour->weekday,
                'start_time' => substr((string) $hour->start_time, 0, 5),
                'end_time' => substr((string) $hour->end_time, 0, 5),
                'starts_on' => $hour->starts_on?->toDateString(),
                'ends_on' => $hour->ends_on?->toDateString(),
            ])
            ->all());

        $reloaded = Livewire::actingAs($admin)->test(SchedulingConfiguration::class);

        self::assertSame([
            '09:00',
            '14:00',
            '18:00',
        ], array_values(array_map(
            static fn (array $hour): string => $hour['start_time'],
            $reloaded->instance()->data['working_hours'],
        )));
        self::assertSame('2026-09-01', CarbonImmutable::parse(
            (string) array_values($reloaded->instance()->data['working_hours'])[0]['starts_on'],
        )->format('Y-m-d'));
    }

    public function test_settings_shows_a_human_validation_error_for_overlapping_intervals(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(SchedulingConfiguration::class)
            ->fillForm([
                'specialist_id' => $specialist->getKey(),
                'working_hours' => [
                    ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
                    ['weekday' => 1, 'start_time' => '12:00', 'end_time' => '15:00'],
                ],
                'clear_working_hours' => false,
            ])
            ->call('save')
            ->assertHasErrors(['working_hours']);

        self::assertStringContainsString(
            'Рабочие интервалы не должны пересекаться.',
            json_encode($component->errors()->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
        self::assertDatabaseCount('specialist_working_hours', 0);
    }

    public function test_crm_create_booking_starts_with_specialist_and_allows_inline_client_creation(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(CreateBooking::class);
        $specialistField = $component->instance()->getSchemaComponent('form.specialist_id');
        $serviceField = $component->instance()->getSchemaComponent('form.service_id');
        $clientField = $component->instance()->getSchemaComponent('form.client_id');
        $partySizeField = $component->instance()->getSchemaComponent('form.party_size', withHidden: true);

        self::assertInstanceOf(Select::class, $specialistField);
        self::assertSame($specialist->getKey(), (int) $component->instance()->data['specialist_id']);
        self::assertSame($specialist->display_name, $specialistField->getOptionLabel());

        self::assertInstanceOf(Select::class, $serviceField);
        self::assertSame($service->name, $serviceField->getOptions()[$service->getKey()] ?? null);

        self::assertInstanceOf(Select::class, $clientField);
        self::assertTrue($clientField->hasCreateOptionActionFormSchema());
        $createClient = $clientField->getCreateOptionUsing();
        self::assertNotNull($createClient);
        $createdClientId = $createClient([
            'full_name' => 'Новый клиент',
            'email' => 'new-client@example.test',
            'phone' => '+77001234567',
            'language' => 'ru',
            'timezone' => 'Asia/Almaty',
            'lead_source' => 'Telegram',
        ]);
        self::assertDatabaseHas('clients', [
            'id' => $createdClientId,
            'organization_id' => $organization->getKey(),
            'full_name' => 'Новый клиент',
            'email' => 'new-client@example.test',
            'language' => 'ru',
            'timezone' => 'Asia/Almaty',
            'lead_source' => 'Telegram',
        ]);

        self::assertInstanceOf(TextInput::class, $partySizeField);
        self::assertFalse($partySizeField->isVisible());
        $component->fillForm(['visit_format' => VisitFormat::HomeVisit->value]);
        self::assertTrue($component->instance()->getSchemaComponent('form.party_size', withHidden: true)->isVisible());
    }

    public function test_journal_create_prefill_keeps_clicked_time_in_the_crm_viewer_timezone(): void
    {
        [$organization, $admin, $specialist] = $this->fixture('UTC', 'Africa/Cairo');
        $specialist->forceFill([
            'staff_user_id' => $admin->getKey(),
            'viewer_timezone' => 'Asia/Bangkok',
        ])->save();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::withQueryParams([
            'starts_at' => '2026-10-05 14:00',
            'specialist_id' => $specialist->getKey(),
            'return_to_journal' => '1',
            'week' => '2026-10-05',
            'view' => 'week',
        ])->actingAs($admin)->test(CreateBooking::class);

        $startsAt = $component->instance()->data['starts_at'];
        self::assertSame('2026-10-05 14:00', CarbonImmutable::parse((string) $startsAt)->format('Y-m-d H:i'));
        $dateTimeField = $component->instance()->getSchemaComponent('form.starts_at');
        self::assertInstanceOf(DateTimePicker::class, $dateTimeField);
        self::assertSame('Asia/Bangkok', $dateTimeField->getTimezone());
    }

    public function test_work_schedule_keeps_selected_specialist_and_month_in_query_state(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        $secondSpecialist = Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'Второй специалист',
        ]);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::withQueryParams([
            'specialist_id' => $secondSpecialist->getKey(),
            'month' => '2026-10',
        ])->actingAs($admin)->test(WorkSchedule::class);

        $component->assertSet('specialistId', $secondSpecialist->getKey())
            ->assertSet('month', '2026-10');

        $remounted = Livewire::withQueryParams([
            'specialist_id' => $secondSpecialist->getKey(),
            'month' => '2026-10',
        ])->actingAs($admin)->test(WorkSchedule::class);

        self::assertSame($secondSpecialist->getKey(), $remounted->instance()->specialistId);
        self::assertSame('2026-10', $remounted->instance()->month);
        self::assertNotSame($specialist->getKey(), $remounted->instance()->specialistId);
    }

    public function test_settings_update_refreshes_effective_calendar_without_overwriting_date_override(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);
        $settings = Livewire::actingAs($admin)->test(SchedulingConfiguration::class);

        $settings->fillForm([
            'specialist_id' => $specialist->getKey(),
            'working_hours' => [[
                'weekday' => 4,
                'start_time' => '09:00',
                'end_time' => '15:00',
                'starts_on' => null,
                'ends_on' => null,
            ]],
            'clear_working_hours' => false,
        ])->call('save')->assertHasNoErrors();

        self::assertDatabaseHas('specialist_working_hours', [
            'organization_id' => $organization->getKey(),
            'specialist_id' => $specialist->getKey(),
            'weekday' => 4,
            'start_time' => '09:00',
            'end_time' => '15:00',
        ]);
        self::assertSame('09:00', app(GetScheduleCalendar::class)->forSpecialist(
            specialist: $specialist,
            dateFrom: '2026-10-08',
            dateTo: '2026-10-08',
            displayTimezone: 'UTC',
        )['2026-10-08']['intervals'][0]['start']);

        app(SetScheduleExceptionSet::class)->handle(
            actor: $admin,
            specialist: $specialist,
            definitionsByDate: [
                '2026-10-08' => [[
                    'exception_date' => '2026-10-08',
                    'exception_type' => ScheduleExceptionType::CustomWindow->value,
                    'start_time' => '12:00',
                    'end_time' => '18:00',
                ]],
            ],
        );

        $settings->fillForm([
            'working_hours' => [[
                'weekday' => 4,
                'start_time' => '10:00',
                'end_time' => '16:00',
                'starts_on' => null,
                'ends_on' => null,
            ]],
            'clear_working_hours' => false,
        ])->call('save')->assertHasNoErrors();

        self::assertDatabaseMissing('specialist_working_hours', [
            'specialist_id' => $specialist->getKey(),
            'weekday' => 4,
            'start_time' => '09:00',
            'end_time' => '15:00',
        ]);
        self::assertDatabaseHas('specialist_working_hours', [
            'specialist_id' => $specialist->getKey(),
            'weekday' => 4,
            'start_time' => '10:00',
            'end_time' => '16:00',
        ]);

        $calendar = app(GetScheduleCalendar::class)->forSpecialist(
            specialist: $specialist,
            dateFrom: '2026-10-08',
            dateTo: '2026-10-15',
            displayTimezone: 'UTC',
        );
        self::assertSame('12:00', $calendar['2026-10-08']['intervals'][0]['start']);
        self::assertSame('10:00', $calendar['2026-10-15']['intervals'][0]['start']);

        app(SetScheduleExceptionSet::class)->handle(
            actor: $admin,
            specialist: $specialist,
            definitionsByDate: ['2026-10-08' => []],
        );

        self::assertSame('10:00', app(GetScheduleCalendar::class)->forSpecialist(
            specialist: $specialist,
            dateFrom: '2026-10-08',
            dateTo: '2026-10-08',
            displayTimezone: 'UTC',
        )['2026-10-08']['intervals'][0]['start']);
    }

    public function test_work_schedule_ignores_foreign_specialist_query_state(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignSpecialist = Specialist::factory()->forOrganization($foreignOrganization)->create();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::withQueryParams([
            'specialist_id' => $foreignSpecialist->getKey(),
            'month' => '2026-10',
        ])->actingAs($admin)->test(WorkSchedule::class);

        self::assertSame($specialist->getKey(), $component->instance()->specialistId);
        self::assertNotSame($foreignSpecialist->getKey(), $component->instance()->specialistId);

        $invalid = Livewire::withQueryParams([
            'specialist_id' => 'not-a-specialist',
            'month' => '2026-10',
        ])->actingAs($admin)->test(WorkSchedule::class);

        self::assertSame($specialist->getKey(), $invalid->instance()->specialistId);
        self::assertSame('2026-10', $invalid->instance()->month);
    }

    public function test_work_schedule_distinguishes_today_from_selected_state_in_rendered_dom(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);
        $component = Livewire::withQueryParams([
            'specialist_id' => $specialist->getKey(),
            'month' => '2026-09',
        ])->actingAs($admin)->test(WorkSchedule::class);

        $today = $this->workScheduleDayButton($component->html(), '2026-09-01');
        self::assertStringContainsString('data-today="true"', $today);
        self::assertStringContainsString('aria-pressed="false"', $today);
        self::assertStringContainsString('ring-1 ring-inset ring-gray-400', $today);
        self::assertStringNotContainsString('ring-2 ring-inset ring-primary-600', $today);
        self::assertStringContainsString('Сегодня', $today);

        $component->call('toggleDate', '2026-09-01');
        $selectedToday = $this->workScheduleDayButton($component->html(), '2026-09-01');
        self::assertStringContainsString('data-today="true"', $selectedToday);
        self::assertStringContainsString('aria-pressed="true"', $selectedToday);
        self::assertStringContainsString('ring-2 ring-inset ring-primary-600', $selectedToday);

        $component->call('toggleDate', '2026-09-01');
        $unselectedToday = $this->workScheduleDayButton($component->html(), '2026-09-01');
        self::assertStringContainsString('aria-pressed="false"', $unselectedToday);
        self::assertStringContainsString('ring-1 ring-inset ring-gray-400', $unselectedToday);
        self::assertStringNotContainsString('ring-2 ring-inset ring-primary-600', $unselectedToday);

        $component->call('toggleDate', '2026-09-02');
        $selectedDate = $this->workScheduleDayButton($component->html(), '2026-09-02');
        self::assertStringContainsString('aria-pressed="true"', $selectedDate);
        $component->call('toggleDate', '2026-09-02');
        $unselectedDate = $this->workScheduleDayButton($component->html(), '2026-09-02');
        self::assertStringContainsString('aria-pressed="false"', $unselectedDate);
    }

    public function test_free_journal_slot_create_route_renders_with_an_unnamed_client(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        Client::factory()->forOrganization($organization)->create(['full_name' => null]);
        $this->resolveFilamentContext($admin, $organization);
        $admin->forceFill(['app_authentication_secret' => 'test-secret'])->save();

        $this->get(BookingResource::getUrl('create').'?'.http_build_query([
            'starts_at' => '2026-10-05 10:00',
            'specialist_id' => $specialist->getKey(),
            'return_to_journal' => '1',
            'week' => '2026-10-05',
            'view' => 'week',
        ]))->assertOk();
    }

    public function test_selected_dates_can_be_cleared_and_return_to_the_recurring_schedule(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        $recurring = [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['weekday' => 1, 'start_time' => '18:00', 'end_time' => '20:00'],
        ];
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, $recurring);

        app(SetScheduleExceptionSet::class)->handle(
            actor: $admin,
            specialist: $specialist,
            definitionsByDate: [
                '2026-09-07' => [[
                    'exception_date' => '2026-09-07',
                    'exception_type' => ScheduleExceptionType::DayOff->value,
                ]],
                '2026-09-14' => [[
                    'exception_date' => '2026-09-14',
                    'exception_type' => ScheduleExceptionType::DayOff->value,
                ]],
            ],
        );

        self::assertSame(2, (int) $specialist->scheduleExceptions()->count());
        self::assertSame(2, $specialist->workingHours()->count());

        app(SetScheduleExceptionSet::class)->handle(
            actor: $admin,
            specialist: $specialist,
            definitionsByDate: [
                '2026-09-07' => [],
            ],
        );

        $calendar = app(GetScheduleCalendar::class)->forSpecialist(
            specialist: $specialist,
            dateFrom: '2026-09-07',
            dateTo: '2026-09-14',
            displayTimezone: 'UTC',
        );

        self::assertSame([
            ['start' => '09:00', 'end' => '12:00', 'start_minutes' => 540, 'end_minutes' => 720],
            ['start' => '18:00', 'end' => '20:00', 'start_minutes' => 1080, 'end_minutes' => 1200],
        ], $calendar['2026-09-07']['intervals']);
        self::assertFalse($calendar['2026-09-14']['is_working']);
        self::assertSame(1, (int) $specialist->scheduleExceptions()->count());
    }

    public function test_returning_to_recurring_schedule_does_not_warn_when_a_booking_still_fits(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '19:00',
        ]]);
        $client = Client::factory()->forOrganization($organization)->create();
        Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => '2026-10-05 10:00:00',
                'ends_at' => '2026-10-05 11:00:00',
                'blocking_ends_at' => '2026-10-05 11:15:00',
            ]);
        app(SetScheduleExceptionSet::class)->handle(
            actor: $admin,
            specialist: $specialist,
            definitionsByDate: [
                '2026-10-05' => [[
                    'exception_type' => ScheduleExceptionType::CustomWindow->value,
                    'start_time' => '09:00',
                    'end_time' => '12:00',
                ]],
            ],
        );

        app(SetScheduleExceptionSet::class)->handle(
            actor: $admin,
            specialist: $specialist,
            definitionsByDate: ['2026-10-05' => []],
        );

        self::assertDatabaseCount('schedule_exceptions', 0);
        self::assertDatabaseCount('bookings', 1);
    }

    public function test_work_schedule_reports_overlap_and_empty_custom_intervals_without_mutation(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);
        $component = Livewire::actingAs($admin)
            ->test(WorkSchedule::class)
            ->set('month', '2026-09')
            ->call('toggleDate', '2026-09-07')
            ->set('overrideType', ScheduleExceptionType::CustomWindow->value)
            ->set('overrideIntervals', [
                ['start_time' => '09:00', 'end_time' => '13:00'],
                ['start_time' => '12:00', 'end_time' => '15:00'],
            ])
            ->call('saveOverride')
            ->assertHasNoErrors();

        self::assertSame('Рабочие интервалы не должны пересекаться.', $component->instance()->errorMessage);
        self::assertDatabaseCount('schedule_exceptions', 0);

        $component
            ->set('overrideIntervals', [])
            ->call('saveOverride')
            ->assertHasNoErrors();

        self::assertSame('Добавьте хотя бы один рабочий интервал.', $component->instance()->errorMessage);
        self::assertDatabaseCount('schedule_exceptions', 0);
    }

    public function test_work_schedule_selects_dates_and_applies_multi_interval_overrides_without_touching_recurring_hours(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['weekday' => 1, 'start_time' => '18:00', 'end_time' => '20:00'],
        ]);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(WorkSchedule::class)
            ->set('month', '2026-09')
            ->call('toggleDate', '2026-09-07')
            ->call('toggleDate', '2026-09-14');

        self::assertSame(['2026-09-07', '2026-09-14'], $component->instance()->selectedDates);

        $component->call('clearSelectedDates')->assertHasNoErrors();
        self::assertSame(2, $specialist->scheduleExceptions()->count());
        self::assertSame(2, $specialist->workingHours()->count());

        $component
            ->call('toggleDate', '2026-09-14')
            ->set('overrideType', 'custom_window')
            ->set('overrideIntervals', [
                ['start_time' => '12:00', 'end_time' => '16:00'],
                ['start_time' => '18:00', 'end_time' => '20:00'],
            ])
            ->call('saveOverride')
            ->assertHasNoErrors();

        $calendar = app(GetScheduleCalendar::class)->forSpecialist(
            specialist: $specialist,
            dateFrom: '2026-09-07',
            dateTo: '2026-09-14',
            displayTimezone: 'UTC',
        );
        self::assertSame('12:00', $calendar['2026-09-07']['intervals'][0]['start']);
        self::assertSame('18:00', $calendar['2026-09-07']['intervals'][1]['start']);

        $component->call('returnToRegularSchedule')->assertHasNoErrors();
        self::assertSame(1, $specialist->scheduleExceptions()->count());
        self::assertSame('09:00', app(GetScheduleCalendar::class)->forSpecialist(
            specialist: $specialist,
            dateFrom: '2026-09-07',
            dateTo: '2026-09-07',
            displayTimezone: 'UTC',
        )['2026-09-07']['intervals'][0]['start']);
    }

    public function test_work_schedule_uses_specialist_schedule_timezone_for_authoring_and_display(): void
    {
        [$organization, $admin, $specialist] = $this->fixture('Asia/Almaty', 'UTC');
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '19:00',
        ]]);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(WorkSchedule::class)
            ->set('month', '2026-10');

        self::assertSame('09:00', $component->instance()->getScheduleDaysProperty()['2026-10-05']['intervals'][0]['start']);
        self::assertStringContainsString('Всемирное время', $component->instance()->specialistScheduleTimezoneLabel());
    }

    public function test_journal_keeps_specialist_week_and_view_in_url_and_booking_navigation_context(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '19:00',
        ]]);
        $client = Client::factory()->forOrganization($organization)->create();
        $this->resolveFilamentContext($admin, $organization);

        $journal = Livewire::withQueryParams([
            'specialist_id' => $specialist->getKey(),
            'week' => '2026-10-05',
            'view' => 'list',
        ])->actingAs($admin)->test(ListBookings::class);

        $journal->assertSet('selectedSpecialistId', $specialist->getKey())
            ->assertSet('weekStart', '2026-10-05')
            ->assertSet('viewMode', 'list');

        parse_str((string) parse_url($journal->instance()->bookingCreationUrl('2026-10-05', '10:00'), PHP_URL_QUERY), $createQuery);
        self::assertSame([
            'starts_at' => '2026-10-05 10:00',
            'return_to_journal' => '1',
            'week' => '2026-10-05',
            'view' => 'list',
            'specialist_id' => (string) $specialist->getKey(),
        ], $createQuery);

        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => '2026-10-05 10:00:00',
                'ends_at' => '2026-10-05 11:00:00',
                'blocking_ends_at' => '2026-10-05 11:15:00',
            ]);
        $bookingPage = Livewire::withQueryParams([
            'return_to_journal' => '1',
            'specialist_id' => $specialist->getKey(),
            'week' => '2026-10-05',
            'view' => 'list',
        ])->actingAs($admin)->test(ViewBooking::class, ['record' => $booking->getKey()]);

        $bookingPage->assertActionVisible('back_to_journal');
    }

    public function test_create_booking_from_journal_preserves_context_and_persists_clicked_instant(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture();
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '19:00',
        ]]);
        $client = Client::factory()->forOrganization($organization)->create();
        $this->resolveFilamentContext($admin, $organization);
        $availability = app(CalculateAvailability::class)->forStaff(
            actor: $admin,
            specialistId: $specialist->getKey(),
            serviceId: $service->getKey(),
            dateFrom: '2026-10-05',
            dateTo: '2026-10-05',
            format: VisitFormat::Online,
            displayTimezone: 'UTC',
        );
        self::assertContains('10:15', array_map(
            static fn ($slot): string => $slot->startsAt->format('H:i'),
            $availability->slots,
        ));

        $query = [
            'starts_at' => '2026-10-05 10:15',
            'specialist_id' => $specialist->getKey(),
            'return_to_journal' => '1',
            'week' => '2026-10-05',
            'view' => 'week',
        ];

        $admin->forceFill(['app_authentication_secret' => 'test-secret'])->save();
        $this->actingAs($admin)
            ->get(BookingResource::getUrl('create').'?'.http_build_query($query))
            ->assertOk();

        Livewire::withQueryParams($query)
            ->actingAs($admin)
            ->test(CreateBooking::class)
            ->fillForm([
                'client_id' => $client->getKey(),
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'starts_at' => CarbonImmutable::create(2026, 10, 5, 10, 15, 0, 'UTC'),
                'visit_format' => VisitFormat::Online->value,
                'party_size' => 1,
            ])
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect(ListBookings::getUrl().'?week=2026-10-05&view=week&specialist_id='.$specialist->getKey());

        self::assertSame('2026-10-05T10:15:00+00:00', Booking::query()->sole()->startsAtUtc()->toIso8601String());
    }

    public function test_create_booking_from_journal_preserves_the_viewer_instant_across_a_date_boundary(): void
    {
        [$organization, $admin, $specialist, $service] = $this->fixture('UTC', 'Asia/Bangkok');
        $specialist->forceFill([
            'staff_user_id' => $admin->getKey(),
            'viewer_timezone' => 'America/Los_Angeles',
        ])->save();
        $service->forceFill(['buffer_minutes' => 0])->save();
        app(SetSpecialistWorkingHours::class)->handle($admin, $specialist, [[
            'weekday' => 1,
            'start_time' => '09:00',
            'end_time' => '19:00',
        ]]);
        $client = Client::factory()->forOrganization($organization)->create();
        $this->resolveFilamentContext($admin, $organization);

        Livewire::withQueryParams([
            'starts_at' => '2026-10-04 19:00',
            'specialist_id' => $specialist->getKey(),
            'return_to_journal' => '1',
            'week' => '2026-10-05',
            'view' => 'week',
        ])
            ->actingAs($admin)
            ->test(CreateBooking::class)
            ->fillForm([
                'client_id' => $client->getKey(),
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'starts_at' => CarbonImmutable::create(2026, 10, 4, 19, 0, 0, 'America/Los_Angeles'),
                'visit_format' => VisitFormat::Online->value,
                'party_size' => 1,
            ])
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        self::assertSame('2026-10-05T02:00:00+00:00', Booking::query()->sole()->startsAtUtc()->toIso8601String());
        self::assertSame('Asia/Bangkok', Booking::query()->sole()->schedule_timezone);
    }

    public function test_journal_ignores_a_foreign_specialist_from_the_url(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignSpecialist = Specialist::factory()->forOrganization($foreignOrganization)->create();
        $this->resolveFilamentContext($admin, $organization);

        $journal = Livewire::withQueryParams([
            'specialist_id' => $foreignSpecialist->getKey(),
            'week' => '2026-10-05',
            'view' => 'week',
        ])->actingAs($admin)->test(ListBookings::class);

        self::assertSame($specialist->getKey(), $journal->instance()->selectedSpecialistId);
        parse_str((string) parse_url($journal->instance()->newBookingUrl(), PHP_URL_QUERY), $newBookingQuery);
        self::assertNotSame((string) $foreignSpecialist->getKey(), (string) ($newBookingQuery['specialist_id'] ?? ''));
    }

    /** @return array{Organization, User, Specialist, Service} */
    private function fixture(string $organizationTimezone = 'UTC', string $specialistTimezone = 'UTC'): array
    {
        $organization = Organization::factory()->create(['timezone' => $organizationTimezone]);
        $admin = User::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create([
            'timezone' => $specialistTimezone,
            'display_name' => 'Специалист графика',
        ]);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 15,
            'formats' => ['online'],
        ]);
        SpecialistServiceAssignment::factory()->create([
            'organization_id' => $organization->getKey(),
            'specialist_id' => $specialist->getKey(),
            'service_id' => $service->getKey(),
        ]);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 9, 1, 8, 0, 0, 'UTC'));

        return [$organization, $admin, $specialist, $service];
    }

    private function resolveFilamentContext(User $admin, Organization $organization): void
    {
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    private function workScheduleDayButton(string $html, string $date): string
    {
        $position = strpos($html, "toggleDate('{$date}')");
        if ($position === false) {
            self::fail('Work Schedule day button was not rendered.');
        }

        $start = strrpos(substr($html, 0, $position), '<button');
        $end = strpos($html, '</button>', $position);
        if ($start === false || $end === false) {
            self::fail('Work Schedule day button boundaries were not rendered.');
        }

        return substr($html, $start, $end - $start + strlen('</button>'));
    }
}
