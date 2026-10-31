<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\BookingStatusAnalytics;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\PurchaseAnalytics;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\BookingEvent;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PurchaseAndBookingStatusAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_types_use_catalog_snapshot_and_booking_statuses_use_events(): void
    {
        [$organization, $admin] = $this->fixture();
        $client = Client::factory()->forOrganization($organization)->create();
        $secondClient = Client::factory()->forOrganization($organization)->create();
        $createdAt = CarbonImmutable::parse('2026-10-10 10:00:00', 'UTC');

        $purchase = DB::table('commerce_purchases')->insertGetId([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => 'paid',
            'total_amount_minor' => 10000,
            'currency' => 'USD',
            'purchase_snapshot' => json_encode(['kind' => 'service'], JSON_THROW_ON_ERROR),
            'paid_at' => $createdAt,
            'refunded_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('commerce_purchase_items')->insert([
            'organization_id' => $organization->getKey(),
            'purchase_id' => $purchase,
            'sellable_type' => Service::class,
            'sellable_id' => 1,
            'quantity' => 1,
            'amount_minor' => 10000,
            'currency' => 'USD',
            'product_snapshot' => json_encode(['catalog_type' => 'service'], JSON_THROW_ON_ERROR),
            'fulfillment_provider' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $secondPurchase = DB::table('commerce_purchases')->insertGetId([
            'organization_id' => $organization->getKey(),
            'client_id' => $secondClient->getKey(),
            'status' => 'paid',
            'total_amount_minor' => 30000,
            'currency' => 'USD',
            'purchase_snapshot' => json_encode(['kind' => 'program'], JSON_THROW_ON_ERROR),
            'paid_at' => $createdAt,
            'refunded_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
        DB::table('commerce_purchase_items')->insert([
            'organization_id' => $organization->getKey(),
            'purchase_id' => $secondPurchase,
            'sellable_type' => Service::class,
            'sellable_id' => 1,
            'quantity' => 1,
            'amount_minor' => 30000,
            'currency' => 'USD',
            'product_snapshot' => json_encode(['catalog_type' => 'program'], JSON_THROW_ON_ERROR),
            'fulfillment_provider' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        foreach (['completed', 'cancelled', 'rescheduled'] as $index => $eventType) {
            $start = $createdAt->addHours($index + 1);
            $booking = Booking::factory()->forClient($client)->forSpecialist($specialist)->forService($service)->create([
                'starts_at' => $start,
                'ends_at' => $start->addHour(),
                'blocking_ends_at' => $start->addHour(),
            ]);
            BookingEvent::factory()->forBooking($booking)->create([
                'event_type' => $eventType,
                'occurred_at' => $createdAt,
            ]);
        }

        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31'],
            'UTC',
            CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
        );

        $purchases = app(PurchaseAnalytics::class)->handle($admin, $period);
        self::assertSame(['Разовые сеансы', 'Пакеты и программы'], array_column($purchases->types, 'label'));
        self::assertSame([1, 1], array_column($purchases->types, 'count'));

        $statuses = app(BookingStatusAnalytics::class)->handle($admin, $period);
        self::assertSame([1, 1, 1], array_column($statuses->statuses, 'count'));

        $programs = app(PurchaseAnalytics::class)->handle($admin, $period, catalogType: 'program');
        self::assertSame(['Пакеты и программы'], array_column($programs->types, 'label'));
        self::assertSame([1], array_column($programs->types, 'count'));

        $cancelled = app(BookingStatusAnalytics::class)->handle($admin, $period, status: 'cancelled');
        self::assertSame([['key' => 'cancelled', 'label' => 'Отменённые', 'count' => 1]], $cancelled->statuses);
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }
}
