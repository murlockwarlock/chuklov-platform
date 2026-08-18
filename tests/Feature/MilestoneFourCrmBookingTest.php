<?php

namespace Tests\Feature;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\AssignSpecialistToService;
use App\Modules\Scheduling\Application\SetSpecialistWorkingHours;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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

        Livewire::actingAs($admin)
            ->test(CreateBooking::class)
            ->fillForm($payload)
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        self::assertSame(1, Booking::query()->count());
        self::assertSame(1, $booking->fresh()->events()->count());
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
            ->assertActionExists('cancel');
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
}
