<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\ListBookableServices;
use App\Modules\Scheduling\Application\ScheduleMutationImpactCalculator;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Services\Application\ListPublishedServices;
use App\Modules\Services\Application\SetServiceActive;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ServiceArchiveLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_archive_removes_service_from_new_use_preserves_history_and_restores_it(): void
    {
        [$organization, $admin, $service, $booking] = $this->fixture();
        $context = app(OrganizationContext::class);
        $context->set($organization);

        $impact = app(ScheduleMutationImpactCalculator::class)->forServiceChange($service, ['is_active' => false]);
        self::assertCount(1, $impact->bookingIds);

        try {
            app(SetServiceActive::class)->handle($admin, $service, false);
            self::fail('Archiving a service with a future booking must require impact confirmation.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('schedule_impact', $exception->errors());
        }

        app(SetServiceActive::class)->handle($admin, $service, false, true, $impact->digest);

        self::assertFalse($service->fresh()->is_active);
        self::assertCount(0, app(ListPublishedServices::class)->handle());
        self::assertCount(0, app(ListBookableServices::class)->handle());
        self::assertDatabaseHas('bookings', ['id' => $booking->getKey(), 'service_id' => $service->getKey()]);

        $restoreImpact = app(ScheduleMutationImpactCalculator::class)->forServiceChange($service->fresh(), ['is_active' => true]);
        app(SetServiceActive::class)->handle($admin, $service->fresh(), true, true, $restoreImpact->digest);

        self::assertTrue($service->fresh()->is_active);
        self::assertCount(1, app(ListPublishedServices::class)->handle());
        self::assertCount(1, app(ListBookableServices::class)->handle());
        self::assertDatabaseHas('bookings', ['id' => $booking->getKey(), 'service_id' => $service->getKey()]);
    }

    public function test_archive_is_tenant_scoped(): void
    {
        [$organization, $admin, $service] = $this->fixture(withBooking: false);
        $otherOrganization = Organization::factory()->create();
        $otherService = Service::factory()->forOrganization($otherOrganization)->create();
        app(OrganizationContext::class)->set($organization);

        $this->expectException(AuthorizationException::class);
        app(SetServiceActive::class)->handle($admin, $otherService, false);
    }

    /** @return array{0: Organization, 1: User, 2: Service, 3: Booking|null} */
    private function fixture(bool $withBooking = true): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        SpecialistServiceAssignment::factory()->forSpecialist($specialist)->forService($service)->create([
            'organization_id' => $organization->getKey(),
        ]);
        $booking = null;

        if ($withBooking) {
            $client = Client::factory()->forOrganization($organization)->create();
            $booking = Booking::factory()
                ->forOrganization($organization)
                ->forClient($client)
                ->forSpecialist($specialist)
                ->forService($service)
                ->create();
        }

        return [$organization, $admin, $service, $booking];
    }
}
