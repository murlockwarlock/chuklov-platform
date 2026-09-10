<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\Application\InitiateFakePayment;
use App\Modules\Finance\Application\ReconcileFakeGatewayTransaction;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Application\SettleFakePayment;
use App\Modules\Finance\Domain\Enums\PaymentGatewayStatus;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\PaymentGatewayTransaction;
use App\Modules\Finance\Domain\ValueObjects\GatewaySettlementEvidence;
use App\Modules\Finance\Infrastructure\Fake\FakePaymentGateway;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PaymentFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_demo_payment_uses_pending_settlement_and_refund_lifecycle_idempotently(): void
    {
        [$organization, $admin, $client, $obligation] = $this->fixture();
        $session = ['client_portal.client_id' => $client->getKey()];

        $this->withSession($session)
            ->get(route('portal.finance.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('obligations.0.demoPayment.canStart', true)
                ->where('obligations.0.demoPayment.canSucceed', false)
                ->where('obligations.0.demoPayment.canFail', false)
                ->where('obligations.0.demoPayment.canRefund', false)
                ->where('obligations.0.demoPayment.stateLabel', 'Demo payment is ready'));

        $this->withSession($session)
            ->post(route('portal.finance.fake.start', $obligation->getKey()), ['idempotency_key' => 'client-demo-start-1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $transaction = PaymentGatewayTransaction::query()->where('organization_id', $organization->getKey())->firstOrFail();
        self::assertSame(PaymentGatewayStatus::Pending, $transaction->status);
        self::assertSame(0, DB::table('financial_ledger_entries')->where('organization_id', $organization->getKey())->count());

        $successRoute = route('portal.finance.fake.simulate', ['transactionId' => $transaction->getKey(), 'outcome' => 'success']);
        $this->withSession($session)->get($successRoute)->assertStatus(405);
        self::assertSame(PaymentGatewayStatus::Pending, $transaction->fresh()->status);

        $this->withSession($session)->post($successRoute)->assertRedirect();
        $settled = $transaction->fresh();
        self::assertSame(PaymentGatewayStatus::Settled, $settled->status);
        self::assertNotNull($settled->ledger_entry_id);
        self::assertSame(1, DB::table('financial_ledger_entries')->where('organization_id', $organization->getKey())->count());
        self::assertSame(1, DB::table('payment_gateway_events')->where('organization_id', $organization->getKey())->count());

        $this->withSession($session)->post($successRoute)->assertRedirect();
        self::assertSame(1, DB::table('financial_ledger_entries')->where('organization_id', $organization->getKey())->count());

        $this->withSession($session)
            ->post(route('portal.finance.fake.start', $obligation->getKey()), ['idempotency_key' => 'client-demo-start-1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        self::assertSame(1, PaymentGatewayTransaction::query()->where('organization_id', $organization->getKey())->count());
        self::assertSame(PaymentGatewayStatus::Settled, $transaction->fresh()->status);

        $refundRoute = route('portal.finance.fake.simulate', ['transactionId' => $transaction->getKey(), 'outcome' => 'refund']);
        $this->withSession($session)->post($refundRoute)->assertRedirect();
        $refunded = $transaction->fresh();
        self::assertSame(PaymentGatewayStatus::Refunded, $refunded->status);
        self::assertNotNull($refunded->refund_ledger_entry_id);
        self::assertSame(2, DB::table('financial_ledger_entries')->where('organization_id', $organization->getKey())->count());
        self::assertSame(2, DB::table('payment_gateway_events')->where('organization_id', $organization->getKey())->count());
        self::assertSame(0, app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey())->applied->minorUnits());

        $this->withSession($session)
            ->post(route('portal.finance.fake.start', $obligation->getKey()), ['idempotency_key' => 'client-demo-start-1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        self::assertSame(1, PaymentGatewayTransaction::query()->where('organization_id', $organization->getKey())->count());

        $this->withSession($session)->post($refundRoute)->assertRedirect();
        self::assertSame(2, DB::table('financial_ledger_entries')->where('organization_id', $organization->getKey())->count());

        self::assertSame('refunded', app(ReconcileFakeGatewayTransaction::class)->handle($admin, $refunded));
    }

    public function test_client_demo_failure_is_verified_and_can_be_replayed_without_a_ledger_entry(): void
    {
        [$organization, , $client, $obligation] = $this->fixture();
        $session = ['client_portal.client_id' => $client->getKey()];

        $this->withSession($session)
            ->post(route('portal.finance.fake.start', $obligation->getKey()), ['idempotency_key' => 'client-demo-failure-1'])
            ->assertRedirect();
        $transaction = PaymentGatewayTransaction::query()->where('organization_id', $organization->getKey())->firstOrFail();
        $failureRoute = route('portal.finance.fake.simulate', ['transactionId' => $transaction->getKey(), 'outcome' => 'fail']);

        $this->withSession($session)->post($failureRoute)->assertRedirect();
        self::assertSame(PaymentGatewayStatus::Failed, $transaction->fresh()->status);
        self::assertSame(0, DB::table('financial_ledger_entries')->where('organization_id', $organization->getKey())->count());
        self::assertSame(1, DB::table('payment_gateway_events')->where('organization_id', $organization->getKey())->count());

        $this->withSession($session)->post($failureRoute)->assertRedirect();
        self::assertSame(1, DB::table('payment_gateway_events')->where('organization_id', $organization->getKey())->count());
    }

    public function test_settlement_rejects_amount_mismatch_and_reused_event_for_another_transaction(): void
    {
        [$organization, $admin, , $obligation] = $this->fixture();
        $first = app(InitiateFakePayment::class)->handle($admin, $obligation, 'admin-demo-first');
        $firstEventId = 'shared-demo-event';
        $mismatchedProof = FakePaymentGateway::proof(
            $organization->getKey(),
            $firstEventId,
            $first->provider_reference,
            $first->amount_minor + 1,
            $first->currency,
        );

        $this->expectException(ValidationException::class);
        app(SettleFakePayment::class)->handle(new GatewaySettlementEvidence(
            organizationId: $organization->getKey(),
            providerEventId: $firstEventId,
            providerReference: $first->provider_reference,
            amountMinor: $first->amount_minor + 1,
            currency: $first->currency,
            proof: $mismatchedProof,
        ));

        self::assertSame(PaymentGatewayStatus::Pending, $first->fresh()->status);
        self::assertSame(0, DB::table('payment_gateway_events')->where('organization_id', $organization->getKey())->count());
    }

    public function test_client_cannot_start_or_simulate_another_clients_transaction(): void
    {
        [$organization, $admin, $client, $obligation] = $this->fixture();
        $otherClient = Client::factory()->forOrganization($organization)->create();
        $transaction = app(InitiateFakePayment::class)->handle($admin, $obligation, 'tenant-demo-1');
        $session = ['client_portal.client_id' => $otherClient->getKey()];

        $this->withSession($session)
            ->post(route('portal.finance.fake.start', $obligation->getKey()), ['idempotency_key' => 'other-client-start'])
            ->assertNotFound();
        $this->withSession($session)
            ->post(route('portal.finance.fake.simulate', ['transactionId' => $transaction->getKey(), 'outcome' => 'success']))
            ->assertNotFound();

        self::assertSame(PaymentGatewayStatus::Pending, $transaction->fresh()->status);
        self::assertSame($client->getKey(), $obligation->fresh()->client_id);
    }

    /** @return array{Organization, User, Client, FinancialObligation} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
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

        return [
            $organization,
            $admin,
            $client,
            FinancialObligation::query()->where('booking_id', $completed->getKey())->firstOrFail(),
        ];
    }
}
