<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Analytics\Application\AcquisitionAnalytics;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\FinanceAnalytics;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MilestoneElevenCAnalyticsPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgresql_analytics_keeps_tenant_scope_and_bigint_aggregate_results(): void
    {
        $this->requirePostgres();
        $now = CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC');
        [$organization, $admin] = $this->organizationWithAdmin();
        [$otherOrganization] = $this->organizationWithAdmin();
        $firstClient = $this->client($organization, '2026-08-10 10:00:00');
        $secondClient = $this->client($organization, '2026-08-11 10:00:00');
        $this->attribution($firstClient, 'source', 'postgres-source');
        $this->attribution($secondClient, 'source', 'postgres-source');
        $booking = $this->booking($firstClient);
        $this->configureFinance($organization);
        $obligation = $this->obligation($organization, $firstClient, $booking);
        $this->ledger($obligation, 1000000000, 'postgres-payment');

        $otherClient = $this->client($otherOrganization, '2026-08-10 10:00:00');
        $this->configureFinance($otherOrganization);
        $otherBooking = $this->booking($otherClient);
        $otherObligation = $this->obligation($otherOrganization, $otherClient, $otherBooking, 'other');
        $this->ledger($otherObligation, 900000000, 'other-payment');

        app(OrganizationContext::class)->set($organization);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-08-01', 'end_date' => '2026-08-27'],
            'UTC',
            $now,
        );

        $acquisition = app(AcquisitionAnalytics::class)->handle($admin, $period);
        $finance = app(FinanceAnalytics::class)->handle($admin, $period);

        self::assertSame(2, $acquisition->newClients);
        self::assertSame(2, collect($acquisition->sources)->firstWhere('label', 'postgres-source')->count);
        self::assertTrue($finance->available);
        self::assertSame('1000000000', $finance->revenueMinor);
    }

    public function test_postgresql_analytics_reserves_low_count_unknown_source_from_known_overflow(): void
    {
        $this->requirePostgres();
        $now = CarbonImmutable::parse('2026-08-27 12:00:00', 'UTC');
        [$organization, $admin] = $this->organizationWithAdmin();
        $knownSources = [];

        for ($sourceIndex = 1; $sourceIndex <= 9; $sourceIndex++) {
            $source = sprintf('postgres-known-%02d', $sourceIndex);
            $knownSources[] = $source;

            for ($clientIndex = 1; $clientIndex <= 2; $clientIndex++) {
                $client = $this->client($organization, '2026-08-10 10:00:00');
                $client->forceFill(['lead_source' => 'legacy-only'])->save();
                $this->attribution($client, 'source', $source);
            }
        }

        $unattributedClient = $this->client($organization, '2026-08-10 10:00:00');
        $unattributedClient->forceFill(['lead_source' => 'legacy-only'])->save();

        app(OrganizationContext::class)->set($organization);
        $period = DashboardPeriod::fromFilters(
            ['period' => DashboardPeriod::Custom, 'start_date' => '2026-08-01', 'end_date' => '2026-08-27'],
            'UTC',
            $now,
        );

        $acquisition = app(AcquisitionAnalytics::class)->handle($admin, $period);
        $sources = collect($acquisition->sources);
        $sourceCounts = $sources->mapWithKeys(fn ($source): array => [$source->label => $source->count]);
        $labels = $sources->pluck('label')->all();

        self::assertSame(19, $acquisition->newClients);
        self::assertContains('Не указан', $labels);
        self::assertSame(1, $sourceCounts->get('Не указан'));
        self::assertContains('Другие', $labels);
        self::assertSame(2, $sourceCounts->get('Другие'));
        self::assertSame(['postgres-known-09'], array_values(array_diff($knownSources, $labels)));
        self::assertSame(19, $sources->sum('count'));
        self::assertCount(10, $acquisition->sources);
        self::assertNotContains('legacy-only', $labels);
    }

    /** @return array{0: Organization, 1: User} */
    private function organizationWithAdmin(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();

        return [$organization, $admin];
    }

    private function client(Organization $organization, string $createdAt): Client
    {
        $client = Client::factory()->forOrganization($organization)->create();
        DB::table('clients')->where('id', $client->getKey())->update([
            'created_at' => CarbonImmutable::parse($createdAt, 'UTC'),
            'updated_at' => CarbonImmutable::parse($createdAt, 'UTC'),
        ]);

        return $client->refresh();
    }

    private function attribution(Client $client, string $sourceType, string $source): void
    {
        $attribution = new ClientAttribution;
        $attribution->forceFill([
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
            'source_type' => $sourceType,
            'source' => $source,
            'capture_channel' => 'portal',
            'capture_context' => 'postgres-test',
            'captured_at' => $client->created_at,
            'accepted_at' => $client->created_at,
        ])->save();
    }

    private function booking(Client $client): Booking
    {
        $specialist = Specialist::factory()->forOrganization($client->organization)->create();
        $service = Service::factory()->forOrganization($client->organization)->create();

        return Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
    }

    private function configureFinance(Organization $organization): void
    {
        $timestamp = now();
        DB::table('organization_currency_configurations')->insert([
            'organization_id' => $organization->getKey(),
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'force_single_currency' => true,
            'rounding_mode' => FinancialRoundingMode::HalfUp->value,
            'version' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        DB::table('organization_allowed_currencies')->insert([
            'organization_id' => $organization->getKey(),
            'currency' => 'USD',
            'created_at' => $timestamp,
        ]);
    }

    private function obligation(Organization $organization, Client $client, Booking $booking, string $key = 'postgres'): FinancialObligation
    {
        $money = Money::ofMinor(1000000000, 'USD');
        $snapshot = [
            'source_amount_minor' => '1000000000',
            'source_currency' => 'USD',
            'target_amount_minor' => '1000000000',
            'target_currency' => 'USD',
            'rate' => '1',
            'rate_id' => null,
            'rate_version' => null,
            'effective_at' => null,
            'rounding_mode' => FinancialRoundingMode::HalfUp->value,
            'source_scale' => $money->scale(),
            'target_scale' => $money->scale(),
        ];
        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => $booking->getKey(),
            'service_id' => $booking->service_id,
            'amount_minor' => 1000000000,
            'currency' => 'USD',
            'base_amount_minor' => 1000000000,
            'base_currency' => 'USD',
            'display_amount_minor' => 1000000000,
            'display_currency' => 'USD',
            'payment_amount_minor' => 1000000000,
            'payment_currency' => 'USD',
            'settlement_amount_minor' => 1000000000,
            'settlement_currency' => 'USD',
            'price_snapshot' => ['amount_minor' => '1000000000'],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => $key,
        ])->save();

        return $obligation->refresh();
    }

    private function ledger(FinancialObligation $obligation, int $amountMinor, string $key): FinancialLedgerEntry
    {
        $entry = new FinancialLedgerEntry;
        $entry->forceFill([
            'organization_id' => $obligation->organization_id,
            'obligation_id' => $obligation->getKey(),
            'entry_type' => 'manual_payment',
            'source' => 'crm',
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'payment_amount_minor' => $amountMinor,
            'payment_currency' => 'USD',
            'base_amount_minor' => $amountMinor,
            'base_currency' => 'USD',
            'display_amount_minor' => $amountMinor,
            'display_currency' => 'USD',
            'settlement_amount_minor' => $amountMinor,
            'settlement_currency' => 'USD',
            'payment_method' => 'cash',
            'occurred_at' => '2026-08-15 10:00:00',
            'idempotency_key' => $key,
        ])->save();

        return $entry->refresh();
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('PostgreSQL is required for this integration test.');
        }
    }
}
