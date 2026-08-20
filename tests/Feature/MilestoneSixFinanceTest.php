<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Finance\Application\CorrectFinancialPayment;
use App\Modules\Finance\Application\CreateFinancialObligation;
use App\Modules\Finance\Application\InitiateFakePayment;
use App\Modules\Finance\Application\ReconcileFakeGatewayTransaction;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Application\RecordManualPayment;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Application\SaveExchangeRate;
use App\Modules\Finance\Application\SettleFakePayment;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Enums\PaymentMethod;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\ValueObjects\GatewaySettlementEvidence;
use App\Modules\Finance\Infrastructure\Fake\FakePaymentGateway;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\ScenarioContextFactory;
use App\Modules\Scenarios\Domain\Enums\ScenarioActionStatus;
use App\Modules\Scenarios\Domain\Models\NotificationTemplate;
use App\Modules\Scenarios\Domain\Models\NotificationTemplateVersion;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Domain\Models\ScenarioRule;
use App\Modules\Scenarios\Domain\ValueObjects\ScenarioRecipient;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class MilestoneSixFinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_booking_snapshots_fixed_price_and_historical_conversion(): void
    {
        [$organization, $admin, $client, $booking] = $this->pricedCompletedBooking('USD', 10000);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'RUB',
            'allowed_currencies' => ['RUB', 'USD'],
            'force_single_currency' => false,
            'rounding_mode' => 'half_up',
        ]);
        app(SaveExchangeRate::class)->handle($admin, 'USD', 'RUB', '90');

        $obligation = app(CreateFinancialObligation::class)->handle($admin, $booking);

        self::assertInstanceOf(FinancialObligation::class, $obligation);
        self::assertSame(10000, $obligation->amount_minor);
        self::assertSame('USD', $obligation->currency->value);
        self::assertSame(900000, $obligation->base_amount_minor);
        $snapshot = $obligation->conversion_snapshots['base'];
        self::assertSame('90', $snapshot['rate']);

        app(SaveExchangeRate::class)->handle($admin, 'USD', 'RUB', '100');
        $unchanged = $obligation->fresh();
        self::assertSame(900000, $unchanged->base_amount_minor);
        self::assertSame('90', $unchanged->conversion_snapshots['base']['rate']);

        self::assertSame($obligation->getKey(), app(CreateFinancialObligation::class)->handle($admin, $booking)?->getKey());
        self::assertSame(1, FinancialObligation::query()->where('organization_id', $organization->getKey())->count());
    }

    public function test_currency_configuration_rejects_missing_directed_rates_atomically_and_allows_same_currency(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);

        try {
            app(SaveCurrencyConfiguration::class)->handle($admin, [
                'base_currency' => 'RUB',
                'display_currency' => 'RUB',
                'allowed_currencies' => ['RUB', 'USD'],
                'force_single_currency' => false,
                'rounding_mode' => 'half_up',
            ]);
            self::fail('A missing USD to RUB rate must reject the configuration.');
        } catch (ValidationException) {
            self::assertDatabaseMissing('organization_currency_configurations', ['organization_id' => $organization->getKey()]);
            self::assertDatabaseMissing('organization_allowed_currencies', ['organization_id' => $organization->getKey()]);
        }

        $configuration = app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'RUB',
            'allowed_currencies' => ['RUB'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        self::assertSame('RUB', $configuration->base_currency->value);
        self::assertSame(1, DB::table('organization_allowed_currencies')->where('organization_id', $organization->getKey())->count());

        try {
            app(SaveCurrencyConfiguration::class)->handle($admin, [
                'base_currency' => 'RUB',
                'display_currency' => 'RUB',
                'allowed_currencies' => ['RUB', 'USD'],
                'force_single_currency' => false,
                'rounding_mode' => 'half_up',
            ]);
            self::fail('Adding a currency without its required directed rate must reject atomically.');
        } catch (ValidationException) {
            self::assertSame('RUB', OrganizationCurrencyConfiguration::query()->where('organization_id', $organization->getKey())->firstOrFail()->base_currency->value);
            self::assertSame(['RUB'], DB::table('organization_allowed_currencies')->where('organization_id', $organization->getKey())->pluck('currency')->all());
        }
    }

    public function test_open_balance_uses_obligation_snapshot_after_rate_change_and_reaches_zero(): void
    {
        [$organization, $admin, $client, $booking] = $this->pricedCompletedBooking('USD', 10000, 'RUB');
        $obligation = FinancialObligation::query()->where('booking_id', $booking->getKey())->firstOrFail();

        app(SaveExchangeRate::class)->handle($admin, 'USD', 'RUB', '200');
        $payment = app(RecordManualPayment::class)->handle($admin, $obligation, '50.00', 'USD', 'cash', now(), null, null, 'rate-change-partial');
        $reconciliation = app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey());

        self::assertSame(5000, $reconciliation->outstanding->minorUnits());
        self::assertSame(450000, $reconciliation->displayOutstanding->minorUnits());
        self::assertSame(450000, $reconciliation->baseOutstanding->minorUnits());
        self::assertGreaterThan(0, $reconciliation->displayOutstanding->minorUnits());
        self::assertSame('200', $payment->conversion_snapshot['display']['rate']);
        self::assertSame('90', $obligation->conversion_snapshots['display']['rate']);

        $portal = $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.finance.index'));
        $portal->assertOk()->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('obligations.0.outstandingMinor', 450000)
            ->where('totals.0.amountMinor', 450000)
            ->where('totals.0.currency', 'RUB'));

        $event = ScenarioEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event_name', 'finance.obligation.created')
            ->firstOrFail();
        $scenarioFactory = app(ScenarioContextFactory::class);
        $scenarioContext = $scenarioFactory->evaluationContext($event);
        self::assertTrue($scenarioFactory->financeDebtIsCurrent($scenarioContext));
        self::assertSame(450000, $scenarioFactory->renderContext(
            $scenarioContext,
            new ScenarioRecipient('client', $client->getKey(), null, 'en'),
        )['finance']['outstanding_amount']);

        $this->actingAs($admin)
            ->get(route('filament.admin.resources.financial-obligations.index'))
            ->assertOk()
            ->assertSee('9000.00 RUB')
            ->assertSee('4500.00 RUB');

        app(RecordManualPayment::class)->handle($admin, $obligation, '50.00', 'USD', 'bank_transfer', now(), null, null, 'rate-change-final');
        $settled = app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey());
        self::assertSame(0, $settled->outstanding->minorUnits());
        self::assertSame(0, $settled->displayOutstanding->minorUnits());
        self::assertTrue($settled->isSettled());
        self::assertFalse($scenarioFactory->financeDebtIsCurrent($scenarioContext));
    }

    public function test_multiple_partial_payments_and_correction_keep_snapshot_valuation_stable(): void
    {
        [$organization, $admin, , $booking] = $this->pricedCompletedBooking('USD', 10000, 'RUB');
        $obligation = FinancialObligation::query()->where('booking_id', $booking->getKey())->firstOrFail();

        app(SaveExchangeRate::class)->handle($admin, 'USD', 'RUB', '200');
        $first = app(RecordManualPayment::class)->handle($admin, $obligation, '25.00', 'USD', 'cash', now(), null, null, 'multi-rate-one');
        app(SaveExchangeRate::class)->handle($admin, 'USD', 'RUB', '150');
        $second = app(RecordManualPayment::class)->handle($admin, $obligation, '25.00', 'USD', 'cash', now(), null, null, 'multi-rate-two');

        $partial = app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey());
        self::assertSame(5000, $partial->outstanding->minorUnits());
        self::assertSame(450000, $partial->displayOutstanding->minorUnits());
        self::assertSame('200', $first->conversion_snapshot['display']['rate']);
        self::assertSame('150', $second->conversion_snapshot['display']['rate']);
        self::assertSame('90', $obligation->fresh()->conversion_snapshots['display']['rate']);

        $correction = app(CorrectFinancialPayment::class)->handle($admin, $first, 'Корректировка после изменения курса.', 'multi-rate-correction');
        $corrected = app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey());
        self::assertSame(7500, $corrected->outstanding->minorUnits());
        self::assertSame(675000, $corrected->displayOutstanding->minorUnits());
        self::assertSame(-2500, $correction->settlement_amount_minor);
        self::assertSame('correction', $correction->entry_type->value);
    }

    public function test_client_debt_totals_keep_unlike_display_currencies_separate(): void
    {
        [$organization, $admin, $client, $booking] = $this->pricedCompletedBooking('USD', 10000, 'RUB');
        $specialist = Specialist::query()->where('organization_id', $organization->getKey())->firstOrFail();
        $service = Service::query()->where('organization_id', $organization->getKey())->firstOrFail();

        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'USD',
            'allowed_currencies' => ['RUB', 'USD'],
            'force_single_currency' => false,
            'rounding_mode' => 'half_up',
        ]);
        $secondBooking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => now()->subHours(5),
                'ends_at' => now()->subHours(4),
                'blocking_ends_at' => now()->subHours(4),
            ]);
        app(CompleteBooking::class)->handle($admin, $secondBooking);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.finance.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->has('totals', 2)
                ->where('totals.0.currency', 'RUB')
                ->where('totals.0.amountMinor', 900000)
                ->where('totals.1.currency', 'USD')
                ->where('totals.1.amountMinor', 10000));
    }

    public function test_inconsistent_obligation_valuation_fails_reconciliation_instead_of_being_clamped(): void
    {
        [$organization, , , $booking] = $this->pricedCompletedBooking('USD', 10000, 'RUB');
        $obligation = FinancialObligation::query()->where('booking_id', $booking->getKey())->firstOrFail();
        $snapshots = $obligation->conversion_snapshots;
        $snapshots['display']['rate'] = '200';
        $obligation->forceFill(['conversion_snapshots' => $snapshots])->save();

        $this->expectException(\UnexpectedValueException::class);
        app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey());
    }

    public function test_manual_partial_payments_reconcile_and_idempotency_prevent_overpayment(): void
    {
        [, $admin, , $booking] = $this->pricedCompletedBooking('USD', 10000);
        $obligation = $this->configureAndCreateObligation($admin, $booking);

        $occurredAt = now()->subMinute();
        $first = app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '30.00',
            currency: 'USD',
            paymentMethod: PaymentMethod::Cash,
            occurredAt: $occurredAt,
            note: 'Первый платёж',
            receipt: null,
            idempotencyKey: 'payment-one',
        );
        $replayed = app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '30.00',
            currency: 'USD',
            paymentMethod: PaymentMethod::Cash,
            occurredAt: $occurredAt,
            note: 'Первый платёж',
            receipt: null,
            idempotencyKey: 'payment-one',
        );
        self::assertSame($first->getKey(), $replayed->getKey());

        $reconciliation = app(ReconcileFinancialObligation::class)->handle($obligation->organization_id, $obligation->getKey());
        self::assertSame(7000, $reconciliation->outstanding->minorUnits());
        self::assertSame(FinancialStatus::PartiallyPaid, $reconciliation->status);

        app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '70.00',
            currency: 'USD',
            paymentMethod: 'bank_transfer',
            occurredAt: now(),
            note: null,
            receipt: null,
            idempotencyKey: 'payment-two',
        );
        $settled = app(ReconcileFinancialObligation::class)->handle($obligation->organization_id, $obligation->getKey());
        self::assertSame(0, $settled->outstanding->minorUnits());
        self::assertSame(FinancialStatus::Settled, $settled->status);

        $this->expectException(ValidationException::class);
        app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '0.01',
            currency: 'USD',
            paymentMethod: 'cash',
            occurredAt: now(),
            note: null,
            receipt: null,
            idempotencyKey: 'payment-three',
        );
    }

    public function test_correction_appends_a_negative_entry_and_preserves_original(): void
    {
        [, $admin, , $booking] = $this->pricedCompletedBooking('USD', 10000);
        $obligation = $this->configureAndCreateObligation($admin, $booking);
        $payment = app(RecordManualPayment::class)->handle($admin, $obligation, '20.00', 'USD', 'cash', now(), null, null, 'payment-correction');

        $correction = app(CorrectFinancialPayment::class)->handle($admin, $payment, 'Ошибка при внесении суммы.', 'correction-one');

        self::assertSame(-2000, $correction->amount_minor);
        self::assertNotNull($correction->corrects_ledger_entry_id);
        self::assertDatabaseHas('financial_ledger_entries', [
            'id' => $payment->getKey(),
            'amount_minor' => 2000,
        ]);
        self::assertSame(0, app(ReconcileFinancialObligation::class)->handle($obligation->organization_id, $obligation->getKey())->applied->minorUnits());
    }

    public function test_payment_conversion_keeps_the_rate_snapshot_after_a_rate_change(): void
    {
        [, $admin, , $booking] = $this->pricedCompletedBooking('USD', 10000);
        $obligation = FinancialObligation::query()->where('booking_id', $booking->getKey())->firstOrFail();
        app(SaveExchangeRate::class)->handle($admin, 'USD', 'RUB', '100');

        $payment = app(RecordManualPayment::class)->handle($admin, $obligation, '100.00', 'USD', 'cash', now(), null, null, 'changed-rate-payment');

        self::assertSame('100', $payment->conversion_snapshot['base']['rate']);
        self::assertSame('90', $obligation->conversion_snapshots['base']['rate']);
        self::assertSame(FinancialStatus::Settled, app(ReconcileFinancialObligation::class)->handle($obligation->organization_id, $obligation->getKey())->status);
    }

    public function test_finance_idempotency_key_cannot_be_reused_for_another_obligation(): void
    {
        [, $admin, $client, $booking] = $this->pricedCompletedBooking('USD', 10000);
        $first = FinancialObligation::query()->where('booking_id', $booking->getKey())->firstOrFail();
        $service = Service::query()->findOrFail($booking->service_id);
        $specialist = Specialist::query()->findOrFail($booking->specialist_id);
        $secondBooking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => now()->subHours(5),
                'ends_at' => now()->subHours(4),
                'blocking_ends_at' => now()->subHours(4),
            ]);
        $secondCompleted = app(CompleteBooking::class)->handle($admin, $secondBooking);
        $second = FinancialObligation::query()->where('booking_id', $secondCompleted->getKey())->firstOrFail();

        app(RecordManualPayment::class)->handle($admin, $first, '10.00', 'USD', 'cash', now(), null, null, 'shared-payment-key');

        $this->expectException(ValidationException::class);
        app(RecordManualPayment::class)->handle($admin, $second, '10.00', 'USD', 'cash', now(), null, null, 'shared-payment-key');
    }

    public function test_receipt_is_private_and_portal_download_is_client_scoped(): void
    {
        Storage::fake('private');
        [$organization, $admin, $client, $booking] = $this->pricedCompletedBooking('USD', 10000);
        $obligation = FinancialObligation::query()->where('booking_id', $booking->getKey())->firstOrFail();
        $receipt = UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf');
        $payment = app(RecordManualPayment::class)->handle($admin, $obligation, '10.00', 'USD', 'cash', now(), null, $receipt, 'receipt-payment');
        $receiptId = (int) DB::table('financial_receipts')->where('ledger_entry_id', $payment->getKey())->value('id');
        $path = (string) DB::table('financial_receipts')->where('id', $receiptId)->value('path');

        Storage::disk('private')->assertExists($path);
        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.finance.receipt', $receiptId))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($admin)
            ->get(route('admin.finance.receipt', $receiptId))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $otherClient = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $otherClient->getKey()])
            ->get(route('portal.finance.receipt', $receiptId))
            ->assertNotFound();
    }

    public function test_fake_gateway_requires_trusted_evidence_and_deduplicates_settlement(): void
    {
        [$organization, $admin, , $booking] = $this->pricedCompletedBooking('USD', 10000);
        $obligation = $this->configureAndCreateObligation($admin, $booking);
        $transaction = app(InitiateFakePayment::class)->handle($admin, $obligation, 'fake-initiation');

        $eventId = 'fake-event-one';
        $proof = FakePaymentGateway::proof(
            $organization->getKey(),
            $eventId,
            $transaction->provider_reference,
            $transaction->amount_minor,
            $transaction->currency,
        );
        $evidence = new GatewaySettlementEvidence(
            organizationId: $organization->getKey(),
            providerEventId: $eventId,
            providerReference: $transaction->provider_reference,
            amountMinor: $transaction->amount_minor,
            currency: $transaction->currency,
            proof: $proof,
        );
        $entry = app(SettleFakePayment::class)->handle($evidence);
        $duplicate = app(SettleFakePayment::class)->handle($evidence);
        $gatewayStatus = app(ReconcileFakeGatewayTransaction::class)->handle($admin, $transaction->fresh());

        self::assertSame($entry->getKey(), $duplicate->getKey());
        self::assertSame('settled', $gatewayStatus);
        self::assertSame(FinancialStatus::Settled, app(ReconcileFinancialObligation::class)->handle($organization->getKey(), $obligation->getKey())->status);
        self::assertSame(1, DB::table('payment_gateway_events')->where('organization_id', $organization->getKey())->count());
    }

    public function test_cross_organization_manual_payment_is_rejected(): void
    {
        [$organization, $admin, , $booking] = $this->pricedCompletedBooking('USD', 10000);
        $obligation = $this->configureAndCreateObligation($admin, $booking);
        $otherOrganization = Organization::factory()->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();
        $this->setOrganization($otherOrganization);

        $this->expectException(AuthorizationException::class);
        app(RecordManualPayment::class)->handle($otherAdmin, $obligation, '1.00', 'USD', 'cash', now(), null, null, 'cross-org');
    }

    public function test_client_portal_finance_is_scoped_to_the_authenticated_client(): void
    {
        [$organization, $admin, $client, $booking] = $this->pricedCompletedBooking('USD', 10000);
        $obligation = FinancialObligation::query()->where('booking_id', $booking->getKey())->firstOrFail();
        $otherClient = Client::factory()->forOrganization($organization)->create();

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.finance.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Finance')
                ->has('obligations', 1)
                ->where('obligations.0.outstandingMinor', 10000));

        $this->withSession(['client_portal.client_id' => $otherClient->getKey()])
            ->get(route('portal.finance.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Finance')
                ->has('obligations', 0));

        $this->withSession(['client_portal.client_id' => $otherClient->getKey()])
            ->get(route('portal.finance.receipt', 999999))
            ->assertNotFound();
    }

    public function test_finance_scenario_event_uses_existing_engine_and_rechecks_debt_before_delivery(): void
    {
        [$organization, $admin, $client, $booking] = $this->pricedCompletedBooking('USD', 10000);
        $template = NotificationTemplate::factory()->forOrganization($organization)->create([
            'template_key' => 'finance-reminder-test',
        ]);
        $version = NotificationTemplateVersion::factory()->forTemplate($template)->create();
        $rule = ScenarioRule::factory()->forOrganization($organization)->usingTemplate($version)->create([
            'trigger_event' => 'finance.obligation.created',
            'conditions' => [['type' => 'finance.has_outstanding_debt', 'operator' => 'equals', 'value' => 'true']],
        ]);
        $event = ScenarioEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event_name', 'finance.obligation.created')
            ->firstOrFail();

        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        $action = ScenarioAction::query()->where('scenario_rule_id', $rule->getKey())->firstOrFail();
        app(RecordManualPayment::class)->handle($admin, FinancialObligation::query()->where('booking_id', $booking->getKey())->firstOrFail(), '100.00', 'USD', 'cash', now(), null, null, 'scenario-settlement');
        $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
        $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);

        app(ExecuteScenarioAction::class)->handle($action->getKey());

        self::assertSame(ScenarioActionStatus::Suppressed, $action->fresh()->status);
        self::assertSame('finance.obligation.created', $event->event_name->value);
        self::assertArrayNotHasKey('note', $event->payload);
    }

    /** @return array{Organization, User, Client, Booking} */
    private function pricedCompletedBooking(string $currency, int $priceMinor, string $displayCurrency = 'USD'): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'price_minor' => $priceMinor,
            'price_currency' => $currency,
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
        $this->setOrganization($organization);

        $rates = [
            ['source_currency' => 'USD', 'target_currency' => 'RUB', 'rate' => '90'],
            ['source_currency' => 'RUB', 'target_currency' => 'USD', 'rate' => '0.011111111111111111'],
        ];

        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => $displayCurrency,
            'allowed_currencies' => ['RUB', 'USD'],
            'force_single_currency' => false,
            'rounding_mode' => 'half_up',
            'rates' => $rates,
        ]);
        app(SaveExchangeRate::class)->handle($admin, 'USD', 'RUB', '90');

        return [$organization, $admin, $client, app(CompleteBooking::class)->handle($admin, $booking)];
    }

    private function configureAndCreateObligation(User $admin, Booking $booking): FinancialObligation
    {
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'USD',
            'allowed_currencies' => ['RUB', 'USD'],
            'force_single_currency' => false,
            'rounding_mode' => 'half_up',
        ]);
        app(SaveExchangeRate::class)->handle($admin, 'USD', 'RUB', '90');

        return FinancialObligation::query()->where('booking_id', $booking->getKey())->firstOrFail();
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }
}
