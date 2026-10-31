<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use App\Modules\Analytics\Application\ClientSegmentQuery;
use App\Modules\Analytics\Domain\Enums\ClientSegment;
use App\Modules\Broadcasts\Domain\Models\BroadcastClientTag;
use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Commerce\Domain\Models\Purchase;
use App\Modules\Commerce\Domain\Models\PurchaseItem;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class ClientSegmentQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_requested_segments_use_configured_service_penalty_and_archive_rules(): void
    {
        [$organization, $admin, $specialist] = $this->fixture();
        $qualifying = Service::factory()->forOrganization($organization)->create(['name' => 'Консультация']);
        $target = Service::factory()->forOrganization($organization)->create(['name' => 'Терапия']);
        $other = Service::factory()->forOrganization($organization)->create(['name' => 'Другое']);
        $setter = app(SetOrganizationSetting::class);
        $setter->handle($admin, OrganizationSettingKey::ClientSegmentSleepingDays, 365);
        $setter->handle($admin, OrganizationSettingKey::ClientSegmentWarmLeadServiceIds, json_encode([$qualifying->getKey()], JSON_THROW_ON_ERROR));
        $setter->handle($admin, OrganizationSettingKey::ClientSegmentWarmLeadTargetServiceIds, json_encode([$target->getKey()], JSON_THROW_ON_ERROR));
        $setter->handle($admin, OrganizationSettingKey::ClientSegmentPenaltyTag, 'penalty');

        $warm = Client::factory()->forOrganization($organization)->create(['created_at' => now()->subDays(10)]);
        $this->booking($organization, $warm, $specialist, $qualifying, BookingStatus::Completed, now()->subDays(2));

        $converted = Client::factory()->forOrganization($organization)->create(['created_at' => now()->subDays(100)]);
        $this->booking($organization, $converted, $specialist, $qualifying, BookingStatus::Completed, now()->subDays(2));
        $this->paidPurchase($organization, $converted, $target);

        $notQualified = Client::factory()->forOrganization($organization)->create(['created_at' => now()->subDays(100)]);
        $this->booking($organization, $notQualified, $specialist, $other, BookingStatus::Completed, now()->subDays(2));

        $cold = Client::factory()->forOrganization($organization)->create(['created_at' => now()->subDays(100)]);
        $this->booking($organization, $cold, $specialist, $qualifying, BookingStatus::NoShow, now()->subDays(2));

        $sleeping = Client::factory()->forOrganization($organization)->create(['created_at' => now()->subDays(500)]);
        $this->booking($organization, $sleeping, $specialist, $other, BookingStatus::Completed, now()->subDays(400));

        $penalty = Client::factory()->forOrganization($organization)->create(['created_at' => now()->subDays(100)]);
        BroadcastClientTag::query()->create([
            'organization_id' => $organization->getKey(),
            'client_id' => $penalty->getKey(),
            'tag' => 'penalty',
        ]);

        $archived = Client::factory()->forOrganization($organization)->create(['created_at' => now()->subDays(100), 'archived_at' => now()->subDay()]);

        app(OrganizationContext::class)->set($organization);
        $segments = app(ClientSegmentQuery::class);
        self::assertSame([$warm->getKey()], $segments->query(ClientSegment::WarmLead)->pluck('id')->all());
        self::assertSame([$cold->getKey()], $segments->query(ClientSegment::ColdLead)->pluck('id')->all());
        self::assertSame([$sleeping->getKey()], $segments->query(ClientSegment::Sleeping)->pluck('id')->all());
        self::assertSame([$penalty->getKey()], $segments->query(ClientSegment::Penalty)->pluck('id')->all());
        self::assertSame([$archived->getKey()], $segments->query(ClientSegment::Archived)->pluck('id')->all());
        self::assertNotContains($converted->getKey(), $segments->query(ClientSegment::WarmLead)->pluck('id')->all());

        $thresholdClient = Client::factory()->forOrganization($organization)->create(['created_at' => now()->subDays(40)]);
        $this->booking($organization, $thresholdClient, $specialist, $other, BookingStatus::Completed, now()->subDays(40));
        $setter->handle($admin, OrganizationSettingKey::ClientSegmentSleepingDays, 30);
        self::assertContains($thresholdClient->getKey(), $segments->query(ClientSegment::Sleeping)->pluck('id')->all());
    }

    public function test_empty_organization_has_zero_count_for_every_segment(): void
    {
        [$organization] = $this->fixture();
        app(OrganizationContext::class)->set($organization);

        $counts = app(ClientSegmentQuery::class)->summary();

        foreach (ClientSegment::cases() as $segment) {
            self::assertSame(0, $counts[$segment->value]);
        }
    }

    /** @return array{0: Organization, 1: User, 2: Specialist} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $specialist];
    }

    private function booking(
        Organization $organization,
        Client $client,
        Specialist $specialist,
        Service $service,
        BookingStatus $status,
        CarbonImmutable|Carbon $startsAt,
    ): Booking {
        $startsAt = CarbonImmutable::instance($startsAt);

        return Booking::factory()->forOrganization($organization)->forClient($client)->forSpecialist($specialist)->forService($service)->create([
            'status' => $status->value,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'blocking_ends_at' => $startsAt->addHour(),
        ]);
    }

    private function paidPurchase(Organization $organization, Client $client, Service $service): Purchase
    {
        $purchase = new Purchase;
        $purchase->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'status' => PurchaseStatus::Paid->value,
            'total_amount_minor' => 10000,
            'currency' => 'RUB',
            'purchase_snapshot' => ['kind' => 'service'],
            'paid_at' => now()->subDay(),
        ])->save();
        $item = new PurchaseItem;
        $item->forceFill([
            'organization_id' => $organization->getKey(),
            'purchase_id' => $purchase->getKey(),
            'sellable_type' => Service::class,
            'sellable_id' => $service->getKey(),
            'quantity' => 1,
            'amount_minor' => 10000,
            'currency' => 'RUB',
            'product_snapshot' => ['service_id' => $service->getKey(), 'catalog_type' => 'service'],
        ])->save();

        return $purchase->refresh();
    }
}
