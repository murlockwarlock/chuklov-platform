<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Finance\Application\ListFinancialObligationsForCrm;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Application\ListPublishedServices;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MilestoneSixFinanceRolloutTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_bootstrap_preserves_existing_priced_service_and_completed_booking_and_is_repeat_safe(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ServiceCatalog->value,
            'enabled' => true,
        ]);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => now()->subHours(3),
                'ends_at' => now()->subHours(2),
                'blocking_ends_at' => now()->subHours(2),
            ]);
        app(OrganizationContext::class)->set($organization);

        $migration = require base_path('database/migrations/2026_08_15_100002_bootstrap_finance_currency_configuration.php');
        $migration->up();
        $migration->up();

        $configuration = OrganizationCurrencyConfiguration::query()->where('organization_id', $organization->getKey())->firstOrFail();
        self::assertTrue($configuration->force_single_currency);
        self::assertSame('USD', $configuration->base_currency->value);
        self::assertSame(['USD'], DB::table('organization_allowed_currencies')->where('organization_id', $organization->getKey())->pluck('currency')->all());
        self::assertTrue(app(ListPublishedServices::class)->handle()->contains($service));

        $completed = app(CompleteBooking::class)->handle($admin, $booking);
        $obligation = FinancialObligation::query()->where('booking_id', $completed->getKey())->firstOrFail();
        self::assertSame(10000, $obligation->settlement_amount_minor);
        self::assertSame('USD', $obligation->settlement_currency->value);

        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_even',
        ]);
        $editedVersion = OrganizationCurrencyConfiguration::query()->where('organization_id', $organization->getKey())->value('version');
        $migration->up();

        self::assertSame($editedVersion, OrganizationCurrencyConfiguration::query()->where('organization_id', $organization->getKey())->value('version'));
        self::assertSame('half_even', OrganizationCurrencyConfiguration::query()->where('organization_id', $organization->getKey())->firstOrFail()->rounding_mode->value);
    }

    public function test_bootstrap_fails_before_writing_when_existing_priced_services_need_owner_currency_choice(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        Service::factory()->forOrganization($organization)->create([
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        Service::factory()->forOrganization($organization)->create([
            'price_minor' => 10000,
            'price_currency' => 'RUB',
        ]);

        $migration = require base_path('database/migrations/2026_08_15_100002_bootstrap_finance_currency_configuration.php');
        try {
            $migration->up();
            self::fail('Multiple priced currencies must block bootstrap before writing a configuration.');
        } catch (\RuntimeException) {
            self::assertSame(0, DB::table('organization_currency_configurations')->where('organization_id', $organization->getKey())->count());
            self::assertSame(0, DB::table('organization_allowed_currencies')->where('organization_id', $organization->getKey())->count());
        }
    }

    public function test_pre_m6_priced_service_configuration_cannot_be_saved_without_required_rate(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        Service::factory()->forOrganization($organization)->create([
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        app(OrganizationContext::class)->set($organization);

        $this->expectException(ValidationException::class);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'RUB',
            'allowed_currencies' => ['RUB', 'USD'],
            'force_single_currency' => false,
            'rounding_mode' => 'half_up',
        ]);
    }

    public function test_postgresql_finance_status_projection_excludes_invalid_snapshot_and_ledger_currency(): void
    {
        $this->requirePostgres();
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $snapshot = [
            'source_amount_minor' => '10000',
            'source_currency' => 'USD',
            'target_amount_minor' => '10000',
            'target_currency' => 'USD',
            'rate' => '1',
            'rate_id' => null,
            'rate_version' => null,
            'effective_at' => null,
            'rounding_mode' => 'half_up',
            'source_scale' => 2,
            'target_scale' => 2,
        ];
        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => $booking->getKey(),
            'service_id' => $service->getKey(),
            'amount_minor' => 10000,
            'currency' => 'USD',
            'base_amount_minor' => 10000,
            'base_currency' => 'USD',
            'display_amount_minor' => 10000,
            'display_currency' => 'USD',
            'payment_amount_minor' => 10000,
            'payment_currency' => 'USD',
            'settlement_amount_minor' => 10000,
            'settlement_currency' => 'USD',
            'price_snapshot' => ['amount_minor' => 10000],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => 'pg-projection-'.$organization->getKey(),
        ])->save();

        $list = app(ListFinancialObligationsForCrm::class);
        self::assertCount(1, $list->applyStatusFilter(
            $list->query($organization->getKey()),
            FinancialStatus::Outstanding->value,
        )->get());

        DB::table('financial_obligations')
            ->where('id', $obligation->getKey())
            ->update(['conversion_snapshots' => json_encode(['base' => $snapshot])]);
        self::assertCount(0, $list->applyStatusFilter(
            $list->query($organization->getKey()),
            FinancialStatus::Outstanding->value,
        )->get());

        DB::table('financial_obligations')
            ->where('id', $obligation->getKey())
            ->update(['conversion_snapshots' => json_encode(['base' => $snapshot, 'display' => $snapshot])]);
        $entry = new FinancialLedgerEntry;
        $entry->forceFill([
            'organization_id' => $organization->getKey(),
            'obligation_id' => $obligation->getKey(),
            'entry_type' => 'manual_payment',
            'source' => 'crm',
            'amount_minor' => 1000,
            'currency' => 'USD',
            'payment_amount_minor' => 1000,
            'payment_currency' => 'USD',
            'base_amount_minor' => 1000,
            'base_currency' => 'USD',
            'display_amount_minor' => 1000,
            'display_currency' => 'RUB',
            'settlement_amount_minor' => 1000,
            'settlement_currency' => 'USD',
            'payment_method' => 'cash',
            'occurred_at' => now(),
            'actor_user_id' => $admin->getKey(),
            'idempotency_key' => 'pg-projection-incompatible',
        ])->save();

        self::assertCount(0, $list->applyStatusFilter(
            $list->query($organization->getKey()),
            FinancialStatus::Outstanding->value,
        )->get());
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The finance rollout tests require PostgreSQL migrations and transaction semantics.');
        }
    }
}
