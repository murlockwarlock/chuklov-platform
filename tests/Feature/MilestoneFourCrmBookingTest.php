<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\CreateMultipleBookings;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\CreateBooking as CreateBookingAction;
use App\Modules\Scheduling\Application\CreateMultipleBookings as CreateMultipleBookingsAction;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class MilestoneFourCrmBookingTest extends TestCase
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

    public function test_authorized_crm_creation_uses_scoped_application_path_and_replays_safely(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->enableClientRecords($organization);
        $this->resolveFilamentContext($admin, $organization);
        $payload = [
            'client_id' => $client->getKey(),
            'service_id' => $service->getKey(),
            'specialist_id' => $specialist->getKey(),
            'starts_at' => CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            'visit_format' => 'office',
            'party_size' => 1,
        ];

        $component = Livewire::actingAs($admin)
            ->test(CreateBooking::class)
            ->fillForm($payload);
        $component
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $booking = Booking::query()->sole();
        self::assertSame($organization->getKey(), $booking->organization_id);
        self::assertSame($client->getKey(), $booking->client_id);
        $scenarioEvent = ScenarioEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event_name', 'booking.created')
            ->where('aggregate_id', (string) $booking->getKey())
            ->sole();
        self::assertSame($booking->getKey(), $scenarioEvent->payload['booking_id']);

        Livewire::actingAs($admin)
            ->test(CreateBooking::class)
            ->fillForm($payload)
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        self::assertSame(1, Booking::query()->count());
        self::assertSame(1, $booking->fresh()->events()->count());
        self::assertSame(1, ScenarioEvent::query()->where('event_name', 'booking.created')->count());
    }

    public function test_crm_can_create_three_independent_bookings_from_one_form(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->enableClientRecords($organization);
        $this->resolveFilamentContext($admin, $organization);
        $slots = [
            CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            CarbonImmutable::create(2026, 4, 13, 9, 0, 0, 'UTC'),
            CarbonImmutable::create(2026, 4, 20, 9, 0, 0, 'UTC'),
        ];

        Livewire::actingAs($admin)
            ->test(CreateMultipleBookings::class)
            ->assertFormFieldExists('slots')
            ->assertSee('Создать записи')
            ->fillForm([
                'client_id' => $client->getKey(),
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'visit_format' => VisitFormat::Office->value,
                'slots' => array_map(static fn (CarbonImmutable $slot): array => [
                    'date' => $slot->toDateString(),
                    'time' => $slot->toIso8601String(),
                ], $slots),
            ])
            ->call('create')
            ->assertHasNoErrors()
            ->assertNotified('Создано 3 записей')
            ->assertRedirect();

        $bookings = Booking::query()->where('organization_id', $organization->getKey())->orderBy('starts_at')->get();
        self::assertCount(3, $bookings);
        self::assertSame([$client->getKey()], $bookings->pluck('client_id')->unique()->values()->all());
        self::assertSame([$specialist->getKey()], $bookings->pluck('specialist_id')->unique()->values()->all());
        self::assertSame([$service->getKey()], $bookings->pluck('service_id')->unique()->values()->all());
        self::assertSame(
            array_map(static fn (CarbonImmutable $slot): string => $slot->toIso8601String(), $slots),
            $bookings->map(static fn (Booking $booking): string => $booking->startsAtUtc()->toIso8601String())->all(),
        );
        self::assertSame(3, ScenarioEvent::query()->where('event_name', 'booking.created')->count());
        self::assertSame(3, DB::table('booking_idempotency_keys')->count());
    }

    public function test_ordinary_single_booking_creation_still_uses_the_existing_form(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->enableClientRecords($organization);
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(CreateBooking::class)
            ->fillForm([
                'client_id' => $client->getKey(),
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'starts_at' => CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
                'visit_format' => VisitFormat::Office->value,
                'party_size' => 1,
            ])
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        self::assertSame(1, Booking::query()->where('organization_id', $organization->getKey())->count());
    }

    public function test_repeating_the_same_multi_booking_submit_replays_without_duplicates(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $slots = [
            CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            CarbonImmutable::create(2026, 4, 13, 9, 0, 0, 'UTC'),
        ];

        $create = function () use ($admin, $client, $specialist, $service, $slots): array {
            return app(CreateMultipleBookingsAction::class)->handle(
                actor: $admin,
                client: $client,
                specialist: $specialist,
                service: $service,
                format: VisitFormat::Office,
                slots: array_map(static fn (CarbonImmutable $slot): array => [
                    'date' => $slot->toDateString(),
                    'time' => $slot->toIso8601String(),
                ], $slots),
                batchIntentKey: 'stable-batch-intent',
            );
        };

        $first = $create();
        $second = $create();

        self::assertSame(
            array_map(static fn (Booking $booking): int => (int) $booking->getKey(), $first),
            array_map(static fn (Booking $booking): int => (int) $booking->getKey(), $second),
        );
        self::assertSame(2, Booking::query()->where('organization_id', $organization->getKey())->count());
        self::assertSame(2, ScenarioEvent::query()->where('event_name', 'booking.created')->count());
        self::assertSame(2, DB::table('booking_idempotency_keys')->count());
    }

    public function test_multi_booking_conflict_rolls_back_the_whole_batch_and_identifies_the_slot(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $occupiedSlot = CarbonImmutable::create(2026, 4, 13, 9, 0, 0, 'UTC');
        app(CreateBookingAction::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: $occupiedSlot,
            format: VisitFormat::Office,
            idempotencyKey: 'occupied-before-batch',
        );

        try {
            app(CreateMultipleBookingsAction::class)->handle(
                actor: $admin,
                client: $client,
                specialist: $specialist,
                service: $service,
                format: VisitFormat::Office,
                slots: [
                    [
                        'date' => '2026-04-06',
                        'time' => '2026-04-06T09:00:00+00:00',
                    ],
                    [
                        'date' => $occupiedSlot->toDateString(),
                        'time' => $occupiedSlot->toIso8601String(),
                    ],
                ],
                batchIntentKey: 'conflicting-batch-intent',
            );
            self::fail('A batch with an occupied slot must be rejected.');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('13.04.2026 09:00', $exception->errors()['slots'][0]);
        }

        self::assertSame(1, Booking::query()->where('organization_id', $organization->getKey())->count());
        self::assertDatabaseMissing('bookings', [
            'organization_id' => $organization->getKey(),
            'starts_at' => '2026-04-06 09:00:00',
        ]);
        self::assertSame(1, ScenarioEvent::query()->where('event_name', 'booking.created')->count());
        self::assertSame(1, DB::table('booking_idempotency_keys')->count());
    }

    public function test_multi_booking_form_keeps_selected_slots_after_a_conflict(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->enableClientRecords($organization);
        $this->resolveFilamentContext($admin, $organization);
        $occupiedSlot = CarbonImmutable::create(2026, 4, 13, 9, 0, 0, 'UTC');
        app(CreateBookingAction::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: $occupiedSlot,
            format: VisitFormat::Office,
            idempotencyKey: 'occupied-before-form-batch',
        );
        $selectedSlots = [
            [
                'date' => '2026-04-06',
                'time' => '2026-04-06T09:00:00+00:00',
            ],
            [
                'date' => $occupiedSlot->toDateString(),
                'time' => $occupiedSlot->toIso8601String(),
            ],
        ];

        $component = Livewire::actingAs($admin)
            ->test(CreateMultipleBookings::class)
            ->fillForm([
                'client_id' => $client->getKey(),
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'visit_format' => VisitFormat::Office->value,
                'slots' => $selectedSlots,
            ])
            ->call('create');
        $component
            ->assertHasFormErrors(['slots'])
            ->assertSee('Слот 13.04.2026 09:00 больше недоступен.');

        self::assertSame($selectedSlots, array_values($component->instance()->data['slots']));
        self::assertSame(1, Booking::query()->where('organization_id', $organization->getKey())->count());
    }

    public function test_multi_booking_rejects_cross_organization_client(): void
    {
        [$organization, $admin, , $specialist, $service] = $this->fixture();
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create(['timezone' => 'UTC']);

        $this->expectException(AuthorizationException::class);

        try {
            app(CreateMultipleBookingsAction::class)->handle(
                actor: $admin,
                client: $otherClient,
                specialist: $specialist,
                service: $service,
                format: VisitFormat::Office,
                slots: [
                    ['date' => '2026-04-06', 'time' => '2026-04-06T09:00:00+00:00'],
                    ['date' => '2026-04-13', 'time' => '2026-04-13T09:00:00+00:00'],
                ],
                batchIntentKey: 'cross-organization-batch',
            );
        } finally {
            self::assertSame($organization->getKey(), app(OrganizationContext::class)->id());
            self::assertSame(0, Booking::query()->count());
        }
    }

    public function test_crm_can_create_a_confirmed_backdated_booking_without_adjusting_datetime(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $past = CarbonImmutable::create(2026, 3, 23, 9, 0, 0, 'UTC');

        $booking = app(CreateBookingAction::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: $past,
            format: VisitFormat::Office,
            idempotencyKey: 'confirmed-backdated-booking',
            confirmedBackdated: true,
        );

        self::assertSame($organization->getKey(), $booking->organization_id);
        self::assertTrue($booking->startsAtUtc()->equalTo($past));
        self::assertSame(1, Booking::query()->count());
    }

    public function test_crm_requires_explicit_confirmation_for_a_backdated_booking(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $past = CarbonImmutable::create(2026, 3, 23, 9, 0, 0, 'UTC');

        try {
            app(CreateBookingAction::class)->handle(
                actor: $admin,
                client: $client,
                specialist: $specialist,
                service: $service,
                startsAt: $past,
                format: VisitFormat::Office,
                idempotencyKey: 'unconfirmed-backdated-booking',
            );
            self::fail('A backdated booking without confirmation must be rejected.');
        } catch (ValidationException $exception) {
            self::assertSame(['Подтвердите создание записи задним числом.'], $exception->errors()['startsAt']);
        }

        self::assertSame($organization->getKey(), app(OrganizationContext::class)->id());
        self::assertSame(0, Booking::query()->count());
    }

    public function test_crm_form_shows_backdated_warning_and_requires_confirmation(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->enableClientRecords($organization);
        $this->resolveFilamentContext($admin, $organization);
        $past = CarbonImmutable::create(2026, 3, 23, 9, 0, 0, 'UTC');

        Livewire::actingAs($admin)
            ->test(CreateBooking::class)
            ->fillForm([
                'client_id' => $client->getKey(),
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'starts_at' => $past,
                'visit_format' => 'office',
                'party_size' => 1,
            ])
            ->assertFormFieldExists('confirm_backdated')
            ->assertSee('Вы создаёте запись задним числом: 23.03.2026 09:00.')
            ->call('create')
            ->assertHasFormErrors(['confirm_backdated']);

        self::assertSame(0, Booking::query()->count());
    }

    public function test_crm_shows_actionable_error_when_selected_time_is_unavailable(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(CreateBooking::class)
            ->fillForm([
                'client_id' => $client->getKey(),
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'starts_at' => CarbonImmutable::create(2026, 4, 6, 9, 30, 0, 'UTC'),
                'visit_format' => VisitFormat::Office->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['starts_at'])
            ->assertSee('Это время уже недоступно. Выберите другое.');

        self::assertSame(0, Booking::query()->count());
    }

    public function test_crm_shows_actionable_error_when_booking_creation_fails_unexpectedly(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        OrganizationFeatureGate::invalidate($organization->getKey(), OrganizationFeature::ClientRecords);

        $component = Livewire::actingAs($admin)
            ->test(CreateBooking::class)
            ->fillForm([
                'specialist_id' => $specialist->getKey(),
                'service_id' => $service->getKey(),
                'booking_date' => '2026-04-06',
                'visit_format' => VisitFormat::Office->value,
            ]);
        $timeField = $component->instance()->getSchemaComponent('form.booking_time');
        self::assertInstanceOf(Select::class, $timeField);
        $selectedTime = array_key_first($timeField->getOptions());
        self::assertIsString($selectedTime);
        $component->fillForm([
            'booking_time' => $selectedTime,
            'client_id' => $client->getKey(),
        ]);
        $this->mock(CreateBookingAction::class, function ($mock): void {
            $mock->shouldReceive('handle')
                ->once()
                ->andThrow(new RuntimeException('booking creation failed'));
        });

        $component
            ->call('create')
            ->assertHasFormErrors(['booking_time'])
            ->assertSee('Не удалось создать запись. Проверьте дату и доступное время и попробуйте ещё раз.');

        self::assertSame(0, Booking::query()->count());
    }

    public function test_booking_form_uses_human_required_messages_instead_of_translation_keys(): void
    {
        [$organization, $admin] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(CreateBooking::class)
            ->fillForm([
                'specialist_id' => null,
                'service_id' => null,
                'client_id' => null,
                'starts_at' => null,
                'visit_format' => null,
            ])
            ->call('create')
            ->assertHasFormErrors(['specialist_id', 'service_id', 'client_id', 'starts_at', 'visit_format'])
            ->assertSee('Выберите услугу.')
            ->assertSee('Выберите клиента.')
            ->assertSee('Выберите специалиста.')
            ->assertSee('Укажите дату и время записи.')
            ->assertSee('Выберите формат визита.');

        self::assertStringNotContainsString('validation.required', $component->html());

        $homeVisit = Livewire::actingAs($admin)->test(CreateBooking::class)
            ->fillForm(['visit_format' => VisitFormat::HomeVisit->value])
            ->set('data.visit_format', VisitFormat::HomeVisit->value)
            ->set('data.party_size', null)
            ->set('data.location', null)
            ->call('create')
            ->assertHasFormErrors(['party_size', 'location'])
            ->assertSee('Укажите количество участников выезда.')
            ->assertSee('Укажите адрес выезда.');

        self::assertStringNotContainsString('validation.required', $homeVisit->html());
    }

    public function test_booking_quick_create_client_is_visible_minimal_and_preserves_form_state(): void
    {
        [$organization, $admin, $existingClient, $specialist, $service] = $this->fixture();
        $organization->forceFill(['timezone' => 'Asia/Almaty'])->save();
        app(OrganizationContext::class)->set($organization->refresh());
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $this->resolveFilamentContext($admin, $organization);
        $startsAt = CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC');

        $component = Livewire::actingAs($admin)
            ->test(CreateBooking::class)
            ->assertFormComponentActionExists('client_id', 'createOption')
            ->assertFormComponentActionHasLabel('client_id', 'createOption', 'Добавить нового клиента')
            ->assertSee('Найдите клиента по имени, телефону, Telegram или email.')
            ->fillForm([
                'specialist_id' => $specialist->getKey(),
                'service_id' => $service->getKey(),
                'booking_date' => $startsAt->setTimezone('Asia/Almaty')->toDateString(),
                'visit_format' => VisitFormat::Office->value,
            ]);

        $timeField = $component->instance()->getSchemaComponent('form.booking_time');
        self::assertInstanceOf(Select::class, $timeField);
        $selectedTime = array_key_first($timeField->getOptions());
        self::assertIsString($selectedTime);
        $component->fillForm(['booking_time' => $selectedTime]);

        $createClientAction = $component->instance()->getSchemaComponent('form.client_id')->getCreateOptionAction();
        self::assertTrue($createClientAction?->isButton());
        self::assertSame('disabled', $createClientAction?->getExtraAttributes()['wire:loading.attr'] ?? null);

        $component
            ->callFormComponentAction('client_id', 'createOption', [
                'full_name' => 'Иван Петров',
                'phone' => '+7 700 123-45-67',
                'email' => 'ivan.petrov@example.test',
            ])
            ->assertHasNoFormComponentActionErrors();

        $createdClient = Client::query()
            ->where('organization_id', $organization->getKey())
            ->where('full_name', 'Иван Петров')
            ->sole();

        self::assertNotSame($existingClient->getKey(), $createdClient->getKey());
        self::assertSame((string) config('portal.default_locale', 'ru'), $createdClient->language);
        self::assertSame('Asia/Almaty', $createdClient->timezone);
        self::assertSame($createdClient->getKey(), (int) $component->instance()->data['client_id']);
        self::assertSame($specialist->getKey(), (int) $component->instance()->data['specialist_id']);
        self::assertSame($service->getKey(), (int) $component->instance()->data['service_id']);
        self::assertSame('office', $component->instance()->data['visit_format']);
        self::assertSame($selectedTime, $component->instance()->data['booking_time']);
        self::assertTrue(CarbonImmutable::parse((string) $component->instance()->data['starts_at'])->equalTo(CarbonImmutable::parse($selectedTime)));

        $component
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $booking = Booking::query()->sole();
        self::assertSame($organization->getKey(), $booking->organization_id);
        self::assertSame($createdClient->getKey(), $booking->client_id);
    }

    public function test_booking_form_offers_only_available_times_and_creates_from_selected_slot(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $organization->forceFill(['timezone' => 'Asia/Yekaterinburg'])->save();
        $specialist->forceFill([
            'timezone' => 'Asia/Bangkok',
            'staff_user_id' => $admin->getKey(),
            'viewer_timezone' => 'Asia/Yekaterinburg',
        ])->save();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        OrganizationFeatureGate::invalidate($organization->getKey(), OrganizationFeature::ClientRecords);
        app(OrganizationContext::class)->set($organization->refresh());
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(CreateBooking::class)
            ->fillForm([
                'client_id' => $client->getKey(),
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'booking_date' => '2026-04-06',
            ]);

        $timeField = $component->instance()->getSchemaComponent('form.booking_time');
        self::assertInstanceOf(Select::class, $timeField);
        self::assertTrue($timeField->isDisabled());
        self::assertStringContainsString('Сначала выберите формат визита.', $component->html());

        $component->fillForm(['visit_format' => VisitFormat::Office->value]);
        $timeField = $component->instance()->getSchemaComponent('form.booking_time');
        self::assertFalse($timeField->isDisabled());
        self::assertSame([
            '07:00–08:00',
            '08:15–09:15',
            '09:30–10:30',
            '10:45–11:45',
            '12:00–13:00',
            '13:15–14:15',
        ], array_values($timeField->getOptions()));

        $selectedTime = array_key_first($timeField->getOptions());
        self::assertIsString($selectedTime);
        $component
            ->fillForm(['booking_time' => $selectedTime])
            ->assertHasNoErrors();

        $component
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $booking = Booking::query()->sole();
        self::assertSame($client->getKey(), $booking->client_id);
        self::assertTrue($booking->startsAtUtc()->equalTo(CarbonImmutable::parse($selectedTime)));
    }

    public function test_client_cannot_use_the_crm_backdated_confirmation(): void
    {
        [$organization, , $client, $specialist, $service] = $this->fixture();
        $past = CarbonImmutable::create(2026, 3, 23, 9, 0, 0, 'UTC');

        $this->expectException(AuthorizationException::class);

        try {
            app(CreateBookingAction::class)->handle(
                actor: $client,
                client: $client,
                specialist: $specialist,
                service: $service,
                startsAt: $past,
                format: VisitFormat::Office,
                idempotencyKey: 'client-backdated-confirmation',
                confirmedBackdated: true,
            );
        } finally {
            self::assertSame($organization->getKey(), app(OrganizationContext::class)->id());
            self::assertSame(0, Booking::query()->count());
        }
    }

    public function test_crm_booking_creation_requires_manage_scheduling_permission(): void
    {
        [$organization] = $this->fixture();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $this->resolveFilamentContext($staff, $organization);

        self::assertFalse(BookingResource::canCreate());
        $this->actingAs($staff)
            ->get(route('filament.admin.resources.bookings.create'))
            ->assertForbidden();
    }

    public function test_crm_booking_creation_generates_idempotency_key_server_side(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->enableClientRecords($organization);
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(CreateBooking::class)
            ->fillForm([
                'client_id' => $client->getKey(),
                'service_id' => $service->getKey(),
                'specialist_id' => $specialist->getKey(),
                'starts_at' => CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
                'visit_format' => 'office',
                'party_size' => 1,
            ])
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        self::assertSame(1, Booking::query()->count());
        self::assertSame(1, DB::table('booking_idempotency_keys')->count());
    }

    public function test_office_bookings_keep_the_default_address_or_store_a_per_booking_override(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        app(SetOrganizationSetting::class)->handle($admin, OrganizationSettingKey::OfficeLocation, 'Адрес по умолчанию');

        $defaultBooking = app(CreateBookingAction::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: 'default-office-address',
        );
        $overrideBooking = app(CreateBookingAction::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 13, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: 'override-office-address',
            location: 'Другой адрес, кабинет 4',
        );

        self::assertSame('Адрес по умолчанию', $defaultBooking->location);
        self::assertSame('Другой адрес, кабинет 4', $overrideBooking->location);

        app(SetOrganizationSetting::class)->handle($admin, OrganizationSettingKey::OfficeLocation, 'Новый адрес по умолчанию');

        self::assertSame('Адрес по умолчанию', $defaultBooking->refresh()->location);
        self::assertSame('Другой адрес, кабинет 4', $overrideBooking->refresh()->location);
    }

    public function test_view_booking_exposes_lifecycle_actions_when_authorized(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        $booking = Booking::factory()->forOrganization($organization)->create([
            'client_id' => $client->id,
            'specialist_id' => $specialist->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Requested,
            'visit_format' => VisitFormat::Office,
            'starts_at' => CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            'ends_at' => CarbonImmutable::create(2026, 4, 6, 10, 0, 0, 'UTC'),
            'blocking_ends_at' => CarbonImmutable::create(2026, 4, 6, 10, 15, 0, 'UTC'),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewBooking::class, ['record' => $booking->getKey()])
            ->assertSuccessful()
            ->assertActionExists('confirm')
            ->assertActionExists('reschedule')
            ->assertActionExists('cancel')
            ->assertActionExists('noShow');
    }

    public function test_reschedule_action_offers_available_slots_and_uses_the_selected_slot(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);
        $booking = app(CreateBookingAction::class)->handle(
            actor: $admin,
            client: $client,
            specialist: $specialist,
            service: $service,
            startsAt: CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            format: VisitFormat::Office,
            idempotencyKey: 'reschedule-slot-options',
        );

        $component = Livewire::actingAs($admin)
            ->test(ViewBooking::class, ['record' => $booking->getKey()])
            ->mountAction('reschedule');
        $timeField = $component->instance()->getSchemaComponent('mountedActionSchema0.booking_time');

        self::assertInstanceOf(Select::class, $timeField);
        self::assertSame([
            '09:00–10:00',
            '10:15–11:15',
            '11:30–12:30',
            '12:45–13:45',
            '14:00–15:00',
            '15:15–16:15',
        ], array_values($timeField->getOptions()));

        $options = $timeField->getOptions();
        $selectedTime = array_keys($options)[1] ?? null;
        self::assertIsString($selectedTime);

        $component
            ->setActionData([
                'booking_date' => '2026-04-06',
                'booking_time' => $selectedTime,
                'starts_at' => $selectedTime,
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Запись успешно перенесена');

        self::assertTrue($booking->fresh()->startsAtUtc()->equalTo(CarbonImmutable::parse($selectedTime)));
    }

    public function test_high_impact_booking_lifecycle_actions_require_confirmation(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);
        $booking = Booking::factory()->forOrganization($organization)->create([
            'client_id' => $client->id,
            'specialist_id' => $specialist->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Requested,
            'visit_format' => VisitFormat::HomeVisit,
            'starts_at' => CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            'ends_at' => CarbonImmutable::create(2026, 4, 6, 10, 0, 0, 'UTC'),
            'blocking_ends_at' => CarbonImmutable::create(2026, 4, 6, 10, 15, 0, 'UTC'),
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewBooking::class, ['record' => $booking->getKey()]);

        foreach (['approveHomeVisit', 'rejectHomeVisit', 'complete', 'noShow', 'cancel'] as $actionName) {
            self::assertTrue($component->instance()->getAction($actionName)->isConfirmationRequired(), $actionName);
        }
    }

    public function test_view_booking_noshow_action_handles_premature_execution_with_notification(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);

        // Future booking
        $booking = Booking::factory()->forOrganization($organization)->create([
            'client_id' => $client->id,
            'specialist_id' => $specialist->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'visit_format' => VisitFormat::Office,
            'starts_at' => CarbonImmutable::now('UTC')->addDays(2),
            'ends_at' => CarbonImmutable::now('UTC')->addDays(2)->addHour(),
            'blocking_ends_at' => CarbonImmutable::now('UTC')->addDays(2)->addHour(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewBooking::class, ['record' => $booking->getKey()])
            ->callAction('noShow', ['reason' => 'Не пришёл'])
            ->assertNotified('Действие отклонено');

        self::assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
    }

    public function test_bookings_open_with_newest_created_record_first(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $older = Booking::factory()->forOrganization($organization)->create([
            'client_id' => $client->id,
            'specialist_id' => $specialist->id,
            'service_id' => $service->id,
            'starts_at' => CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            'ends_at' => CarbonImmutable::create(2026, 4, 6, 10, 0, 0, 'UTC'),
            'blocking_ends_at' => CarbonImmutable::create(2026, 4, 6, 10, 15, 0, 'UTC'),
            'created_at' => CarbonImmutable::now()->subMinute(),
        ]);
        $newer = Booking::factory()->forOrganization($organization)->create([
            'client_id' => $client->id,
            'specialist_id' => $specialist->id,
            'service_id' => $service->id,
            'starts_at' => CarbonImmutable::create(2026, 4, 7, 9, 0, 0, 'UTC'),
            'ends_at' => CarbonImmutable::create(2026, 4, 7, 10, 0, 0, 'UTC'),
            'blocking_ends_at' => CarbonImmutable::create(2026, 4, 7, 10, 15, 0, 'UTC'),
            'created_at' => CarbonImmutable::now(),
        ]);
        $this->resolveFilamentContext($admin, $organization);

        $records = Livewire::actingAs($admin)
            ->test(ListBookings::class)
            ->instance()
            ->getTableRecords();

        self::assertSame([$newer->getKey(), $older->getKey()], $records->pluck('id')->all());
    }

    public function test_confirm_action_refreshes_the_visible_booking_state(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $this->resolveFilamentContext($admin, $organization);
        $booking = Booking::factory()->forOrganization($organization)->create([
            'client_id' => $client->id,
            'specialist_id' => $specialist->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Requested,
            'visit_format' => VisitFormat::Office,
            'starts_at' => CarbonImmutable::create(2026, 4, 6, 9, 0, 0, 'UTC'),
            'ends_at' => CarbonImmutable::create(2026, 4, 6, 10, 0, 0, 'UTC'),
            'blocking_ends_at' => CarbonImmutable::create(2026, 4, 6, 10, 15, 0, 'UTC'),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewBooking::class, ['record' => $booking->getKey()])
            ->callAction('confirm')
            ->assertNotified('Запись подтверждена')
            ->assertSee('Подтверждена')
            ->assertDontSee('Ожидает подтверждения');
    }

    /** @return array{Organization, User, Client, Specialist, Service} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'duration_minutes' => 60,
            'buffer_minutes' => 15,
            'formats' => ['office'],
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
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

    private function resolveFilamentContext(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        app(OrganizationContext::class)->set($organization);
    }

    private function enableClientRecords(Organization $organization): void
    {
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        OrganizationFeatureGate::invalidate($organization->getKey(), OrganizationFeature::ClientRecords);
    }
}
