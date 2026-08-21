<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingOperationalFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 19, 12, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_today_uses_the_organization_timezone_and_excludes_the_next_local_day(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture('UTC', 'Asia/Almaty');
        $beforeLocalDay = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::create(2026, 8, 18, 17, 59, 0, 'UTC'),
        );
        $localDayStart = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::create(2026, 8, 18, 19, 0, 0, 'UTC'),
        );
        $nextLocalDayStart = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::create(2026, 8, 19, 19, 0, 0, 'UTC'),
        );

        Livewire::actingAs($admin)
            ->test(ListBookings::class)
            ->set('activeTab', 'today')
            ->assertCanSeeTableRecords([$localDayStart])
            ->assertCanNotSeeTableRecords([$beforeLocalDay, $nextLocalDayStart]);
    }

    public function test_upcoming_includes_pending_review_and_excludes_past_or_terminal_bookings(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $futureRequested = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::now('UTC')->addHour(),
            BookingStatus::Requested,
        );
        $futurePendingReview = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::now('UTC')->addHours(2),
            BookingStatus::PendingReview,
            VisitFormat::HomeVisit,
        );
        $futureConfirmed = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::now('UTC')->addHours(3),
        );
        $pastConfirmed = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::now('UTC')->subHour(),
        );
        $futureCancelled = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::now('UTC')->addHours(4),
            BookingStatus::Cancelled,
        );

        Livewire::actingAs($admin)
            ->test(ListBookings::class)
            ->set('activeTab', 'upcoming')
            ->assertCanSeeTableRecords([$futureRequested, $futurePendingReview, $futureConfirmed])
            ->assertCanNotSeeTableRecords([$pastConfirmed, $futureCancelled]);
    }

    public function test_pending_confirmation_is_limited_to_requested_status(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $requested = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::now('UTC')->addHour(),
            BookingStatus::Requested,
        );
        $pendingReview = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::now('UTC')->addHours(2),
            BookingStatus::PendingReview,
            VisitFormat::HomeVisit,
        );
        $confirmed = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::now('UTC')->addHours(3),
        );

        Livewire::actingAs($admin)
            ->test(ListBookings::class)
            ->set('activeTab', 'pending_confirmation')
            ->assertCanSeeTableRecords([$requested])
            ->assertCanNotSeeTableRecords([$pendingReview, $confirmed]);
    }

    public function test_custom_period_uses_local_date_boundaries_as_a_half_open_range(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture('UTC', 'Asia/Almaty');
        $included = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::create(2026, 8, 19, 19, 0, 0, 'UTC'),
        );
        $excluded = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::create(2026, 8, 20, 19, 0, 0, 'UTC'),
        );

        Livewire::actingAs($admin)
            ->test(ListBookings::class)
            ->filterTable('period', [
                'from' => '2026-08-20',
                'until' => '2026-08-20',
            ])
            ->assertCanSeeTableRecords([$included])
            ->assertCanNotSeeTableRecords([$excluded]);
    }

    public function test_booking_filters_and_relationship_selectors_are_organization_scoped_and_bounded(): void
    {
        [$organization, $admin, $client, $specialist, $service] = $this->fixture();
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $otherSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create();
        $otherService = Service::factory()->forOrganization($otherOrganization)->create();
        $ownBooking = $this->createBooking(
            $organization,
            $client,
            $specialist,
            $service,
            CarbonImmutable::now('UTC')->addHour(),
            BookingStatus::Requested,
            VisitFormat::Online,
        );
        $otherBooking = $this->createBooking(
            $otherOrganization,
            $otherClient,
            $otherSpecialist,
            $otherService,
            CarbonImmutable::now('UTC')->addHour(),
        );

        $component = Livewire::actingAs($admin)
            ->test(ListBookings::class)
            ->filterTable('status', BookingStatus::Requested)
            ->filterTable('specialist', $specialist->getKey())
            ->filterTable('service', $service->getKey())
            ->filterTable('visit_format', VisitFormat::Online)
            ->filterTable('client', $client->getKey())
            ->assertCanSeeTableRecords([$ownBooking])
            ->assertCanNotSeeTableRecords([$otherBooking]);

        foreach (['specialist', 'service', 'client'] as $filterName) {
            $filter = $component->instance()->getTable()->getFilter($filterName);
            self::assertInstanceOf(SelectFilter::class, $filter);
            self::assertSame(50, $filter->getOptionsLimit());
            self::assertTrue($filter->isPreloaded());
            $component->instance()->getTableFiltersForm();
            $field = $filter->getSchema()->getFlatFields()['value'];
            self::assertInstanceOf(Select::class, $field);
            $options = $filter->getOptionsFromRelationship($field);
            self::assertSame(
                [$filterName === 'specialist' ? $specialist->getKey() : ($filterName === 'service' ? $service->getKey() : $client->getKey())],
                $filter->getRelationshipQuery()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all(),
            );
            self::assertArrayHasKey(
                $filterName === 'specialist' ? $specialist->getKey() : ($filterName === 'service' ? $service->getKey() : $client->getKey()),
                $options,
            );
            self::assertArrayNotHasKey(
                $filterName === 'specialist' ? $otherSpecialist->getKey() : ($filterName === 'service' ? $otherService->getKey() : $otherClient->getKey()),
                $options,
            );
        }
    }

    public function test_booking_relationship_filter_options_are_bounded_scoped_and_searchable_before_typing(): void
    {
        [$organization, $admin] = $this->fixture();
        $targetSpecialist = Specialist::factory()->forOrganization($organization)->create([
            'display_name' => 'A Target Specialist',
        ]);
        $targetService = Service::factory()->forOrganization($organization)->create([
            'name' => 'A Target Service',
        ]);
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $foreignSpecialist = Specialist::factory()->forOrganization($otherOrganization)->create([
            'display_name' => 'A Target Specialist',
        ]);
        $foreignService = Service::factory()->forOrganization($otherOrganization)->create([
            'name' => 'A Target Service',
        ]);

        for ($index = 0; $index < 55; $index++) {
            Specialist::factory()->forOrganization($organization)->create([
                'display_name' => 'Z Specialist '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);
            Service::factory()->forOrganization($organization)->create([
                'name' => 'Z Service '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $component = Livewire::actingAs($admin)->test(ListBookings::class);

        foreach ([
            'specialist' => [$targetSpecialist, $foreignSpecialist],
            'service' => [$targetService, $foreignService],
        ] as $filterName => [$target, $foreign]) {
            $filter = $component->instance()->getTable()->getFilter($filterName);
            self::assertInstanceOf(SelectFilter::class, $filter);

            $component->instance()->getTableFiltersForm();
            $field = $filter->getSchema()->getFlatFields()['value'];
            self::assertInstanceOf(Select::class, $field);
            self::assertTrue($filter->isPreloaded());
            self::assertSame(50, $filter->getOptionsLimit());
            $options = $filter->getOptionsFromRelationship($field);
            self::assertIsArray($options);
            self::assertCount(50, $options);
            self::assertArrayHasKey($target->getKey(), $options);
            self::assertArrayNotHasKey($foreign->getKey(), $options);

            $searchResults = $filter->getSearchResultsFromRelationship($field, 'A Target');
            self::assertArrayHasKey($target->getKey(), $searchResults);
            self::assertArrayNotHasKey($foreign->getKey(), $searchResults);
        }
    }

    /** @return array{Organization, User, Client, Specialist, Service} */
    private function fixture(string $organizationTimezone = 'UTC', ?string $configuredTimezone = null): array
    {
        $organization = Organization::factory()->create(['timezone' => $organizationTimezone]);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'formats' => ['office', 'home', 'online'],
        ]);

        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        app(AssignSpecialistToService::class)->handle($admin, $specialist, $service);

        if ($configuredTimezone !== null) {
            app(OrganizationContext::class)->defaultTimezone();
            app(SetOrganizationSetting::class)->handle(
                actor: $admin,
                key: OrganizationSettingKey::DefaultTimezone,
                value: $configuredTimezone,
            );
        }

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return [$organization, $admin, $client, $specialist, $service];
    }

    private function createBooking(
        Organization $organization,
        Client $client,
        Specialist $specialist,
        Service $service,
        CarbonImmutable $startsAt,
        BookingStatus $status = BookingStatus::Confirmed,
        VisitFormat $visitFormat = VisitFormat::Office,
    ): Booking {
        return Booking::factory()->forOrganization($organization)->create([
            'client_id' => $client->getKey(),
            'specialist_id' => $specialist->getKey(),
            'service_id' => $service->getKey(),
            'status' => $status->value,
            'visit_format' => $visitFormat->value,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'blocking_ends_at' => $startsAt->addHour(),
            'schedule_timezone' => 'UTC',
        ]);
    }
}
