<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PortalHomeBookingProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_skips_requested_bookings_and_uses_the_earliest_confirmed_or_pending_review_booking(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $now = CarbonImmutable::instance(now())->utc();

        $requested = $this->booking($organization, $client, $specialist, $service, BookingStatus::Requested, $now->addDay());
        $pendingReview = $this->booking($organization, $client, $specialist, $service, BookingStatus::PendingReview, $now->addDays(2));
        $confirmed = $this->booking($organization, $client, $specialist, $service, BookingStatus::Confirmed, $now->addDays(3));

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Home')
                ->where('upcomingBooking.id', $pendingReview->getKey()));

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.bookings.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/MyBookings')
                ->has('upcoming', 3)
                ->where('upcoming.0.id', $requested->getKey())
                ->where('upcoming.1.id', $pendingReview->getKey())
                ->where('upcoming.2.id', $confirmed->getKey()));
    }

    public function test_home_empty_schedule_is_preserved_when_only_a_requested_booking_exists(): void
    {
        [$organization, $client, $specialist, $service] = $this->portalFixture();
        $this->booking(
            $organization,
            $client,
            $specialist,
            $service,
            BookingStatus::Requested,
            CarbonImmutable::instance(now())->utc()->addDay(),
        );

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Home')
                ->where('upcomingBooking', null));
    }

    /** @return array{Organization, Client, Specialist, Service} */
    private function portalFixture(): array
    {
        $organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        config()->set('portal.telegram.bot_username', 'chuklov_test_bot');

        return [
            $organization,
            Client::factory()->forOrganization($organization)->create(),
            Specialist::factory()->forOrganization($organization)->create(),
            Service::factory()->forOrganization($organization)->create(),
        ];
    }

    private function booking(
        Organization $organization,
        Client $client,
        Specialist $specialist,
        Service $service,
        BookingStatus $status,
        CarbonImmutable $startsAt,
    ): Booking {
        return Booking::factory()
            ->forOrganization($organization)
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => $status->value,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addHour(),
                'blocking_ends_at' => $startsAt->addHour(),
            ]);
    }
}
