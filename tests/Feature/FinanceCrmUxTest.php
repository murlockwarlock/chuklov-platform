<?php

namespace Tests\Feature;

use App\Filament\Pages\FinanceConfiguration;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\FinancialObligations\FinancialPaymentsRelationManager;
use App\Filament\Resources\FinancialObligations\Pages\ListFinancialObligations;
use App\Filament\Resources\FinancialObligations\Pages\ViewFinancialObligation;
use App\Models\User;
use App\Modules\Finance\Application\CorrectFinancialPayment;
use App\Modules\Finance\Application\InitiateFakePayment;
use App\Modules\Finance\Application\RecordManualPayment;
use App\Modules\Finance\Application\SaveCurrencyConfiguration;
use App\Modules\Finance\Application\SettleFakePayment;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\OrganizationCurrencyConfiguration;
use App\Modules\Finance\Domain\ValueObjects\GatewaySettlementEvidence;
use App\Modules\Finance\Infrastructure\Fake\FakePaymentGateway;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Scheduling\Application\CompleteBooking;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class FinanceCrmUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_list_uses_human_columns_actions_and_statuses(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(ListFinancialObligations::class);

        $component
            ->assertSuccessful()
            ->assertTableColumnExists('client.full_name')
            ->assertTableColumnExists('service.name')
            ->assertTableColumnExists('visit_date')
            ->assertTableColumnExists('amount_summary')
            ->assertTableColumnExists('paid_summary')
            ->assertTableColumnExists('outstanding_summary')
            ->assertTableColumnExists('financial_status')
            ->assertTableFilterExists('status')
            ->assertTableFilterExists('client')
            ->assertTableFilterExists('service')
            ->assertTableFilterExists('visit_date')
            ->assertTableActionExists('view', null, $obligation)
            ->assertTableActionExists('recordPayment', null, $obligation)
            ->assertTableActionExists('openBooking', null, $obligation)
            ->assertTableActionDoesNotExist('fakeGateway', null, $obligation)
            ->assertTableActionDoesNotExist('correctPayment', null, $obligation);

        self::assertStringNotContainsString('ledger', strtolower($component->html()));
        self::assertStringNotContainsString('обязатель', mb_strtolower($component->html()));
    }

    public function test_finance_list_statuses_follow_reconciliation_for_partial_and_full_payment(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $this->resolveFilamentContext($admin, $organization);

        $list = Livewire::actingAs($admin)->test(ListFinancialObligations::class);
        $list->assertTableColumnStateSet('financial_status', 'К оплате', $obligation);

        app(RecordManualPayment::class)->handle($admin, $obligation, '25.00', 'USD', 'cash', now(), null, null, 'status-partial');
        $partial = Livewire::actingAs($admin)->test(ListFinancialObligations::class);
        $partial->assertTableColumnStateSet('financial_status', 'Оплачено частично', $obligation);

        app(RecordManualPayment::class)->handle($admin, $obligation, '75.00', 'USD', 'bank_transfer', now(), null, null, 'status-settled');
        $settled = Livewire::actingAs($admin)->test(ListFinancialObligations::class);
        $settled
            ->assertTableColumnStateSet('financial_status', 'Оплачено', $obligation)
            ->set('tableFilters.status.value', FinancialStatus::Settled->value)
            ->assertCountTableRecords(1);
    }

    public function test_invalid_historical_valuation_is_visible_without_crashing_the_finance_list(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: false);
        $snapshots = $obligation->conversion_snapshots;
        unset($snapshots['display']);
        $obligation->forceFill(['conversion_snapshots' => $snapshots])->save();
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(ListFinancialObligations::class)
            ->assertTableColumnStateSet('financial_status', 'Расчёт недоступен', $obligation);

        Livewire::actingAs($admin)
            ->test(ViewFinancialObligation::class, ['record' => $obligation->getRouteKey()])
            ->assertSee('Расчёт недоступен. Проверьте историю оплат.');
    }

    public function test_payment_modal_defaults_to_settlement_currency_and_records_a_payment(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(ListFinancialObligations::class);

        $component
            ->mountTableAction('recordPayment', $obligation)
            ->assertFormFieldExists('amount')
            ->assertFormFieldExists('currency')
            ->assertFormFieldExists('payment_method')
            ->assertFormFieldExists('occurred_at')
            ->assertFormFieldExists('note')
            ->assertFormFieldExists('receipt')
            ->assertFormFieldHidden('currency')
            ->assertTableActionDataSet([
                'amount' => '100.00',
                'currency' => 'USD',
            ]);

        $component
            ->setTableActionData([
                'amount' => '25.00',
                'payment_method' => 'cash',
                'occurred_at' => '2026-08-21 12:00',
                'note' => 'Оплата в клинике',
                'idempotency_key' => 'crm-ux-payment-one',
            ])
            ->callMountedTableAction();

        $payment = FinancialLedgerEntry::query()->where('obligation_id', $obligation->getKey())->sole();

        self::assertSame('USD', $payment->payment_currency->value);
        self::assertSame(2500, $payment->payment_amount_minor);
        self::assertSame('Оплата в клинике', $payment->note);
        self::assertSame('2026-08-21 07:00:00', $payment->occurred_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_multi_currency_payment_default_uses_settlement_amount_not_display_amount(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(
            serviceCurrency: 'USD',
            displayCurrency: 'RUB',
            singleCurrency: false,
        );
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(ListFinancialObligations::class);

        $component
            ->mountTableAction('recordPayment', $obligation)
            ->assertTableActionDataSet([
                'amount' => '100.00',
                'currency' => 'USD',
            ]);
    }

    public function test_history_exposes_manual_correction_but_keeps_original_payment(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $this->resolveFilamentContext($admin, $organization);
        $payment = app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '20.00',
            currency: 'USD',
            paymentMethod: 'cash',
            occurredAt: CarbonImmutable::now('UTC'),
            note: 'Исходная оплата',
            receipt: null,
            idempotencyKey: 'history-payment',
        );

        $component = Livewire::actingAs($admin)->test(ViewFinancialObligation::class, [
            'record' => $obligation->getRouteKey(),
        ]);

        $component->assertSuccessful();

        $history = Livewire::actingAs($admin)->test(FinancialPaymentsRelationManager::class, [
            'ownerRecord' => $obligation,
            'pageClass' => ViewFinancialObligation::class,
        ]);

        $history
            ->assertSuccessful()
            ->loadTable()
            ->assertTableColumnExists('occurred_at')
            ->assertTableColumnExists('amount_summary')
            ->assertTableColumnExists('payment_method_summary')
            ->assertTableColumnExists('note')
            ->assertTableColumnExists('receipt_summary')
            ->assertTableActionExists('correctPayment', null, $payment);

        $correction = app(CorrectFinancialPayment::class)->handle(
            actor: $admin,
            original: $payment,
            reason: 'Исправлена сумма.',
            idempotencyKey: 'history-correction',
        );

        self::assertSame(-2000, $correction->settlement_amount_minor);
        self::assertDatabaseHas('financial_ledger_entries', [
            'id' => $payment->getKey(),
            'amount_minor' => 2000,
        ]);
        self::assertStringContainsString('Расчёт по визиту', $component->html());
    }

    public function test_view_only_finance_user_can_inspect_configuration_but_cannot_save(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $this->setOrganization($organization);
        $admin = User::factory()->forOrganization($organization)->create();
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'allowed_currencies' => ['USD'],
            'force_single_currency' => true,
            'rounding_mode' => 'half_up',
        ]);
        $this->resolveFilamentContext($staff, $organization);

        $component = Livewire::actingAs($staff)->test(FinanceConfiguration::class);

        $component
            ->assertSuccessful()
            ->assertFormFieldDisabled('base_currency')
            ->assertFormFieldDisabled('display_currency')
            ->assertFormFieldDisabled('force_single_currency');

        self::assertSame('Настройки валют', FinanceConfiguration::getNavigationLabel());
        self::assertStringNotContainsString('Сохранить финансовые настройки', $component->html());
        self::assertStringNotContainsString('Мультивалютные расчёты', $component->html());
        self::assertNotNull(OrganizationCurrencyConfiguration::query()->where('organization_id', $organization->getKey())->first());
    }

    public function test_view_only_finance_user_cannot_record_payment_from_the_list(): void
    {
        [$organization, , , , $obligation] = $this->financeFixture(singleCurrency: true);
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $this->resolveFilamentContext($staff, $organization);

        Livewire::actingAs($staff)
            ->test(ListFinancialObligations::class)
            ->assertTableActionHidden('recordPayment', $obligation)
            ->assertTableActionVisible('view', $obligation);
    }

    public function test_manage_finance_user_sees_advanced_currency_controls_only_in_multi_currency_mode(): void
    {
        [$organization, $admin] = $this->financeFixture(
            serviceCurrency: 'USD',
            displayCurrency: 'RUB',
            singleCurrency: false,
        );
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(FinanceConfiguration::class)
            ->assertSuccessful()
            ->assertSee('Мультивалютные расчёты')
            ->assertSee('Курсы конвертации')
            ->assertSee('Например: 1 USD = 500 KZT')
            ->assertSee('Сохранить финансовые настройки');
    }

    public function test_finance_list_is_tenant_scoped_and_cross_organization_record_fails_closed(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(ListFinancialObligations::class);

        self::assertCount(1, $component->instance()->getTableRecords());
        self::assertSame($obligation->getKey(), $component->instance()->getTableRecords()->first()->getKey());

        $this->expectException(AuthorizationException::class);
        $this->setOrganization($otherOrganization);
        app(RecordManualPayment::class)->handle(
            actor: $otherAdmin,
            obligation: $obligation,
            amount: '1.00',
            currency: 'USD',
            paymentMethod: 'cash',
            occurredAt: CarbonImmutable::now('UTC'),
            note: null,
            receipt: null,
            idempotencyKey: 'cross-org-ux',
        );
    }

    public function test_finance_list_ledger_query_count_does_not_grow_with_page_size(): void
    {
        [$organization, $admin, $client, $booking] = $this->financeFixture(singleCurrency: true);
        $service = Service::query()->whereKey($booking->service_id)->firstOrFail();
        $specialist = Specialist::query()->whereKey($booking->specialist_id)->firstOrFail();

        for ($index = 1; $index <= 12; $index++) {
            $additionalBooking = Booking::factory()
                ->forClient($client)
                ->forSpecialist($specialist)
                ->forService($service)
                ->create([
                    'starts_at' => CarbonImmutable::create(2026, 8, 19, 9, 0, 0, 'UTC')->subDays($index),
                    'ends_at' => CarbonImmutable::create(2026, 8, 19, 10, 0, 0, 'UTC')->subDays($index),
                    'blocking_ends_at' => CarbonImmutable::create(2026, 8, 19, 10, 0, 0, 'UTC')->subDays($index),
                ]);
            app(CompleteBooking::class)->handle($admin, $additionalBooking);
        }

        $this->resolveFilamentContext($admin, $organization);
        DB::enableQueryLog();

        $smallPage = Livewire::actingAs($admin)
            ->test(ListFinancialObligations::class)
            ->set('tableRecordsPerPage', 1);
        $smallLedgerQueries = $this->ledgerQueries(DB::getQueryLog());
        DB::flushQueryLog();

        $smallPage->set('tableRecordsPerPage', 25);
        $largeLedgerQueries = $this->ledgerQueries(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertLessThanOrEqual(5, count($smallLedgerQueries), 'small='.json_encode($smallLedgerQueries));
        self::assertLessThanOrEqual(5, count($largeLedgerQueries), 'large='.json_encode($largeLedgerQueries));
        self::assertLessThanOrEqual(
            count($smallLedgerQueries) + 1,
            count($largeLedgerQueries),
            'Finance ledger reads grew with the number of rendered obligations.',
        );
    }

    public function test_client_and_booking_contexts_link_to_the_same_finance_summary(): void
    {
        [$organization, $admin, $client, $booking, $obligation] = $this->financeFixture(singleCurrency: true);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $this->resolveFilamentContext($admin, $organization);

        $clientPage = Livewire::actingAs($admin)->test(ViewClient::class, [
            'record' => $client->getRouteKey(),
        ]);
        $clientPage
            ->assertSuccessful()
            ->assertSee('К оплате')
            ->assertSee('Открыть оплаты')
            ->assertSee('tableFilters');

        $bookingPage = Livewire::actingAs($admin)->test(ViewBooking::class, [
            'record' => $booking->getRouteKey(),
        ]);
        $bookingPage
            ->assertSuccessful()
            ->assertSee('Расчёт')
            ->assertSee('100.00 USD')
            ->assertActionExists('openPayment')
            ->assertActionExists('recordBookingPayment');

        self::assertStringContainsString((string) $obligation->getKey(), $bookingPage->html());
    }

    public function test_legacy_fake_payment_is_visible_in_history_but_has_no_correction_action(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $this->resolveFilamentContext($admin, $organization);
        $transaction = app(InitiateFakePayment::class)->handle($admin, $obligation, 'fake-ux-payment');
        $eventId = 'fake-ux-event';
        $proof = FakePaymentGateway::proof(
            $organization->getKey(),
            $eventId,
            $transaction->provider_reference,
            $transaction->amount_minor,
            $transaction->currency,
        );

        app(SettleFakePayment::class)->handle(new GatewaySettlementEvidence(
            organizationId: $organization->getKey(),
            providerEventId: $eventId,
            providerReference: $transaction->provider_reference,
            amountMinor: $transaction->amount_minor,
            currency: $transaction->currency,
            proof: $proof,
        ));
        $entry = FinancialLedgerEntry::query()->where('obligation_id', $obligation->getKey())->sole();

        $history = Livewire::actingAs($admin)->test(FinancialPaymentsRelationManager::class, [
            'ownerRecord' => $obligation,
            'pageClass' => ViewFinancialObligation::class,
        ]);

        $history
            ->loadTable()
            ->assertSee('Тестовая оплата')
            ->assertTableActionHidden('correctPayment', $entry);
    }

    /** @return array{Organization, User, Client, Booking, FinancialObligation} */
    private function financeFixture(
        string $serviceCurrency = 'USD',
        string $displayCurrency = 'USD',
        bool $singleCurrency = false,
    ): array {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $admin = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Иван Петров']);
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create([
            'name' => 'Первичная консультация',
            'price_minor' => 10000,
            'price_currency' => $serviceCurrency,
        ]);
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create([
                'starts_at' => CarbonImmutable::create(2026, 8, 20, 9, 0, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 8, 20, 10, 0, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 8, 20, 10, 0, 0, 'UTC'),
            ]);
        $this->setOrganization($organization);

        $allowed = $singleCurrency ? [$serviceCurrency] : ['RUB', 'USD'];
        $baseCurrency = $singleCurrency ? $serviceCurrency : 'RUB';

        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => $baseCurrency,
            'display_currency' => $singleCurrency ? $serviceCurrency : $displayCurrency,
            'allowed_currencies' => $allowed,
            'force_single_currency' => $singleCurrency,
            'rounding_mode' => 'half_up',
            'rates' => $singleCurrency ? [] : [
                ['source_currency' => 'USD', 'target_currency' => 'RUB', 'rate' => '500'],
                ['source_currency' => 'RUB', 'target_currency' => 'USD', 'rate' => '0.002'],
            ],
        ]);
        $completedBooking = app(CompleteBooking::class)->handle($admin, $booking);
        $obligation = FinancialObligation::query()->where('booking_id', $completedBooking->getKey())->firstOrFail();

        return [$organization, $admin, $client, $completedBooking, $obligation];
    }

    private function setOrganization(Organization $organization): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
    }

    private function resolveFilamentContext(User $user, Organization $organization): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->setOrganization($organization);
    }

    /** @param array<int, array{query: string}> $queries */
    private function ledgerQueries(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'financial_ledger_entries'),
        ));
    }
}
