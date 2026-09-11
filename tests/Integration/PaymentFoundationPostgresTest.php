<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Finance\Application\InitiateFakePayment;
use App\Modules\Finance\Application\RefundFakePayment;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Application\SettleFakePayment;
use App\Modules\Finance\Domain\Contracts\PaymentGateway;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundEvidence;
use App\Modules\Finance\Domain\ValueObjects\GatewayRefundRequest;
use App\Modules\Finance\Domain\ValueObjects\GatewaySettlementEvidence;
use App\Modules\Finance\Infrastructure\Fake\FakePaymentGateway;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PaymentFoundationPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    public function test_postgres_enforces_gateway_lifecycle_schema_and_verified_replay_safe_refund(): void
    {
        $this->requirePostgres();

        self::assertTrue(Schema::hasColumns('payment_gateway_transactions', ['refund_ledger_entry_id', 'refunded_at']));
        self::assertTrue(Schema::hasColumn('payment_gateway_events', 'event_type'));

        [$organization, $admin, $obligation] = $this->fixture();
        $transaction = app(InitiateFakePayment::class)->handle($admin, $obligation, 'pg-payment-1');
        $eventId = 'pg-settlement-event';
        $evidence = new GatewaySettlementEvidence(
            organizationId: $organization->getKey(),
            providerEventId: $eventId,
            providerReference: $transaction->provider_reference,
            amountMinor: $transaction->amount_minor,
            currency: $transaction->currency,
            proof: FakePaymentGateway::proof(
                $organization->getKey(),
                $eventId,
                $transaction->provider_reference,
                $transaction->amount_minor,
                $transaction->currency,
            ),
        );
        $entry = app(SettleFakePayment::class)->handle($evidence);
        $replayedEntry = app(SettleFakePayment::class)->handle($evidence);

        self::assertSame($entry->getKey(), $replayedEntry->getKey());
        self::assertSame(PaymentGatewayStatus::Settled, $transaction->fresh()->status);
        self::assertSame(1, DB::table('payment_gateway_events')->where('organization_id', $organization->getKey())->count());
        self::assertSame('settlement', DB::table('payment_gateway_events')->value('event_type'));

        $gateway = app(PaymentGateway::class);
        $refund = $gateway->refund(new GatewayRefundRequest(
            organizationId: $organization->getKey(),
            providerReference: $transaction->provider_reference,
            amountMinor: $transaction->amount_minor,
            currency: $transaction->currency,
            idempotencyKey: 'pg-refund-1',
        ));
        $refundEvidence = new GatewayRefundEvidence(
            organizationId: $organization->getKey(),
            providerEventId: $refund->providerEventId,
            providerReference: $refund->providerReference,
            amountMinor: $refund->amountMinor,
            currency: $refund->currency,
            proof: FakePaymentGateway::refundProof(
                $organization->getKey(),
                $refund->providerEventId,
                $refund->providerReference,
                $refund->amountMinor,
                $refund->currency,
            ),
        );
        $refundEntry = app(RefundFakePayment::class)->handle($refundEvidence);
        $replayedRefund = app(RefundFakePayment::class)->handle($refundEvidence);

        self::assertSame($refundEntry->getKey(), $replayedRefund->getKey());
        self::assertSame(PaymentGatewayStatus::Refunded, $transaction->fresh()->status);
        self::assertSame(2, DB::table('payment_gateway_events')->where('organization_id', $organization->getKey())->count());
        self::assertSame(2, DB::table('financial_ledger_entries')->where('organization_id', $organization->getKey())->count());
        self::assertSame(['settlement', 'refund'], DB::table('payment_gateway_events')->orderBy('id')->pluck('event_type')->all());

        $replayedSettlement = app(SettleFakePayment::class)->handle($evidence);
        self::assertSame($entry->getKey(), $replayedSettlement->getKey());
        self::assertSame(2, DB::table('financial_ledger_entries')->where('organization_id', $organization->getKey())->count());
    }

    public function test_postgres_rejects_unknown_payment_gateway_status(): void
    {
        $this->requirePostgres();
        [$organization, $admin, $obligation] = $this->fixture();
        $transaction = app(InitiateFakePayment::class)->handle($admin, $obligation, 'pg-payment-invalid-status');

        $this->expectException(QueryException::class);
        DB::table('payment_gateway_transactions')
            ->where('organization_id', $organization->getKey())
            ->whereKey($transaction->getKey())
            ->update(['status' => 'unknown']);
    }

    /** @return array{Organization, User, FinancialObligation} */
    private function fixture(): array
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
                'starts_at' => now()->subHours(2),
                'ends_at' => now()->subHour(),
                'blocking_ends_at' => now()->subHour(),
            ]);
        app(OrganizationContext::class)->set($organization);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        $completed = app(CompleteBooking::class)->handle($admin, $booking);

        return [$organization, $admin, FinancialObligation::query()->where('booking_id', $completed->getKey())->firstOrFail()];
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The payment foundation tests require PostgreSQL constraints and transaction semantics.');
        }
    }
}
