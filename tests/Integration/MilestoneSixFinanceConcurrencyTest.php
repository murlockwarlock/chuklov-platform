<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Finance\Application\CreateFinancialObligation;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Application\RecordManualPayment;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Application\SaveExchangeRate;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MilestoneSixFinanceConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_two_processes_replay_one_manual_payment_key_once(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $obligation] = $this->fixture();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::recordInProcess($organization->id, $admin->id, $obligation->id, '100.00', 'same-payment-key'),
            static fn (): string => self::recordInProcess($organization->id, $admin->id, $obligation->id, '100.00', 'same-payment-key'),
        ]);

        self::assertCount(2, $results);
        self::assertNotContains('error', $results);
        self::assertSame(1, count(array_unique($results)));
        self::assertSame(1, DB::table('financial_ledger_entries')->where('organization_id', $organization->id)->count());
        self::assertSame(1, DB::table('finance_idempotency_keys')->where('organization_id', $organization->id)->count());
        self::assertTrue(app(ReconcileFinancialObligation::class)->handle($organization->id, $obligation->id)->isSettled());
    }

    public function test_two_processes_apply_different_partial_payments_without_losing_one(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $obligation] = $this->fixture();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::recordInProcess($organization->id, $admin->id, $obligation->id, '60.00', 'partial-payment-a'),
            static fn (): string => self::recordInProcess($organization->id, $admin->id, $obligation->id, '40.00', 'partial-payment-b'),
        ]);

        self::assertNotContains('error', $results);
        self::assertNotContains('validation', $results);
        self::assertSame(2, DB::table('financial_ledger_entries')->where('organization_id', $organization->id)->count());
        self::assertSame(0, app(ReconcileFinancialObligation::class)->handle($organization->id, $obligation->id)->outstanding->minorUnits());
    }

    public function test_two_processes_cannot_overpay_the_final_balance(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $obligation] = $this->fixture();

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::recordInProcess($organization->id, $admin->id, $obligation->id, '60.00', 'final-payment-a'),
            static fn (): string => self::recordInProcess($organization->id, $admin->id, $obligation->id, '60.00', 'final-payment-b'),
        ]);

        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'entry:'))));
        self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'validation')));
        self::assertSame(1, DB::table('financial_ledger_entries')->where('organization_id', $organization->id)->count());
        self::assertSame(4000, app(ReconcileFinancialObligation::class)->handle($organization->id, $obligation->id)->outstanding->minorUnits());
    }

    public function test_two_processes_materialize_one_completed_obligation(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $obligation, $booking] = $this->fixture(includeObligation: false);

        $results = Concurrency::driver('process')->run([
            static fn (): string => self::createObligationInProcess($organization->id, $admin->id, $booking->id),
            static fn (): string => self::createObligationInProcess($organization->id, $admin->id, $booking->id),
        ]);

        self::assertCount(2, $results);
        self::assertNotContains('error', $results);
        self::assertSame(1, count(array_unique($results)));
        self::assertSame(1, FinancialObligation::query()->where('organization_id', $organization->id)->count());
        self::assertSame(1, DB::table('scenario_events')->where('organization_id', $organization->id)->where('event_name', 'finance.obligation.created')->count());
        self::assertNotNull($obligation);
    }

    public function test_postgresql_ledger_history_cannot_be_updated_or_deleted(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $obligation] = $this->fixture();
        $entry = app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '10.00',
            currency: 'USD',
            paymentMethod: 'cash',
            occurredAt: now(),
            note: null,
            receipt: null,
            idempotencyKey: 'immutable-ledger-entry',
        );

        $updated = false;
        try {
            DB::table('financial_ledger_entries')->whereKey($entry->getKey())->update(['note' => 'mutated']);
            $updated = true;
        } catch (QueryException) {
        }

        $deleted = false;
        try {
            DB::table('financial_ledger_entries')->whereKey($entry->getKey())->delete();
            $deleted = true;
        } catch (QueryException) {
        }

        self::assertFalse($updated);
        self::assertFalse($deleted);
        self::assertDatabaseHas('financial_ledger_entries', [
            'organization_id' => $organization->id,
            'id' => $entry->id,
            'amount_minor' => 1000,
        ]);
    }

    public function test_postgresql_finance_amount_columns_are_integer_and_cross_org_ledger_links_fail(): void
    {
        $this->requirePostgres();
        [$organization, , $obligation] = $this->fixture();
        $otherOrganization = Organization::factory()->create();
        $columns = DB::select(
            "SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'financial_ledger_entries' AND column_name IN ('amount_minor', 'payment_amount_minor', 'base_amount_minor', 'display_amount_minor', 'settlement_amount_minor')",
        );

        self::assertCount(5, $columns);
        foreach ($columns as $column) {
            self::assertSame('bigint', $column->data_type);
        }

        $this->expectException(QueryException::class);
        DB::table('financial_ledger_entries')->insert([
            'organization_id' => $otherOrganization->id,
            'obligation_id' => $obligation->id,
            'entry_type' => 'manual_payment',
            'source' => 'crm',
            'amount_minor' => 100,
            'currency' => 'USD',
            'payment_amount_minor' => 100,
            'payment_currency' => 'USD',
            'base_amount_minor' => 9000,
            'base_currency' => 'RUB',
            'display_amount_minor' => 100,
            'display_currency' => 'USD',
            'settlement_amount_minor' => 100,
            'settlement_currency' => 'USD',
            'occurred_at' => now(),
            'idempotency_key' => 'cross-organization-ledger',
            'created_at' => now(),
        ]);
    }

    /** @return array{Organization, User, FinancialObligation, Booking} */
    private function fixture(bool $includeObligation = true): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $specialist = Specialist::factory()->forOrganization($organization)->create(['timezone' => 'UTC']);
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'status' => BookingStatus::Completed->value,
                'starts_at' => now()->subHours(3),
                'ends_at' => now()->subHours(2),
                'blocking_ends_at' => now()->subHours(2),
            ]);
        app(OrganizationContext::class)->set($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'USD',
            'allowed_currencies' => ['RUB', 'USD'],
            'force_single_currency' => false,
            'rounding_mode' => 'half_up',
        ]);
        app(SaveExchangeRate::class)->handle($admin, 'USD', 'RUB', '90');
        $obligation = $includeObligation
            ? app(CreateFinancialObligation::class)->handle($admin, $booking)
            : null;

        return [$organization, $admin, $obligation ?? new FinancialObligation, $booking];
    }

    private static function recordInProcess(int $organizationId, int $adminId, int $obligationId, string $amount, string $key): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $entry = app(RecordManualPayment::class)->handle(
                actor: User::query()->findOrFail($adminId),
                obligation: FinancialObligation::query()->findOrFail($obligationId),
                amount: $amount,
                currency: 'USD',
                paymentMethod: 'cash',
                occurredAt: now(),
                note: null,
                receipt: null,
                idempotencyKey: $key,
            );

            return 'entry:'.$entry->getKey();
        } catch (ValidationException) {
            return 'validation';
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private static function createObligationInProcess(int $organizationId, int $adminId, int $bookingId): string
    {
        try {
            $organization = Organization::query()->findOrFail($organizationId);
            app(OrganizationContext::class)->set($organization);
            $obligation = app(CreateFinancialObligation::class)->handle(
                actor: User::query()->findOrFail($adminId),
                booking: Booking::query()->findOrFail($bookingId),
            );

            return 'obligation:'.$obligation?->getKey();
        } catch (\Throwable $exception) {
            return 'error:'.get_class($exception).':'.$exception->getMessage();
        }
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The M6 race tests require PostgreSQL row locks and unique indexes.');
        }
    }
}
