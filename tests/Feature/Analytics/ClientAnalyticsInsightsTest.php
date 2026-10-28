<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\ClientAnalyticsInsights;
use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

final class ClientAnalyticsInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_insights_are_scoped_and_include_segments_visits_and_services(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $client = Client::factory()->forOrganization($organization)->create();
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        $service = Service::factory()->forOrganization($organization)->create(['name' => 'Восстановление']);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $foreignService = Service::factory()->forOrganization($foreignOrganization)->create();
        $foreignSpecialist = Specialist::factory()->forOrganization($foreignOrganization)->create();
        $completedAt = CarbonImmutable::now('UTC')->subDays(2)->setTime(10, 0);

        Booking::factory()->forClient($client)->forService($service)->forSpecialist($specialist)->create([
            'status' => BookingStatus::Completed->value,
            'starts_at' => $completedAt,
            'ends_at' => $completedAt->addHour(),
            'blocking_ends_at' => $completedAt->addHour(),
        ]);
        Booking::factory()->forClient($client)->forService($service)->forSpecialist($specialist)->create([
            'status' => BookingStatus::NoShow->value,
            'starts_at' => $completedAt->addDay(),
            'ends_at' => $completedAt->addDay()->addHour(),
            'blocking_ends_at' => $completedAt->addDay()->addHour(),
        ]);
        Booking::factory()->forClient($client)->forService($service)->forSpecialist($specialist)->create([
            'status' => BookingStatus::Confirmed->value,
            'starts_at' => CarbonImmutable::now('UTC')->addDay()->setTime(10, 0),
        ]);
        Booking::factory()->forClient($foreignClient)->forService($foreignService)->forSpecialist($foreignSpecialist)->create([
            'status' => BookingStatus::Completed->value,
        ]);

        app(OrganizationContext::class)->set($organization);
        $insights = app(ClientAnalyticsInsights::class)->handle($admin, $client);

        self::assertSame(3, $insights->bookingCount);
        self::assertSame(1, $insights->completedVisitCount);
        self::assertSame(1, $insights->noShowCount);
        self::assertSame(1, $insights->upcomingBookingCount);
        self::assertSame(['Восстановление' => 1], collect($insights->services)->mapWithKeys(
            static fn (array $service): array => [$service['name'] => $service['visit_count']],
        )->all());
        self::assertContains(ClientSegment::NewClients->label(), $insights->segments);
        self::assertNotContains('Чужой', $insights->segments);
    }

    public function test_insights_reject_a_client_from_another_organization(): void
    {
        [$organization, $admin] = $this->organizationWithAdmin();
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        app(OrganizationContext::class)->set($organization);

        $this->expectException(NotFoundHttpException::class);

        app(ClientAnalyticsInsights::class)->handle($admin, $foreignClient);
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();

        return [$organization, $admin];
    }
}
