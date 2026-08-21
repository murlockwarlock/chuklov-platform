<?php

namespace Tests\Feature;

use App\Filament\Pages\FinanceConfiguration;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\FinancialObligations\FinancialPaymentsRelationManager;
use App\Filament\Resources\FinancialObligations\Pages\ListFinancialObligations;
use App\Filament\Resources\FinancialObligations\Pages\ViewFinancialObligation;
use App\Filament\Support\FinancePaymentActions;
use App\Filament\Support\FinancePresentation;
use App\Models\User;
use App\Modules\Finance\Application\CorrectFinancialPayment;
use App\Modules\Finance\Application\GetBookingFinanceSummary;
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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
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
        $list
            ->assertTableColumnStateSet('financial_status', 'К оплате', $obligation)
            ->set('tableFilters.status.value', FinancialStatus::Outstanding->value)
            ->assertCountTableRecords(1);

        app(RecordManualPayment::class)->handle($admin, $obligation, '25.00', 'USD', 'cash', now(), null, null, 'status-partial');
        $partial = Livewire::actingAs($admin)->test(ListFinancialObligations::class);
        $partial
            ->assertTableColumnStateSet('financial_status', 'Оплачено частично', $obligation)
            ->set('tableFilters.status.value', FinancialStatus::PartiallyPaid->value)
            ->assertCountTableRecords(1);

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

        $filtered = Livewire::actingAs($admin)
            ->test(ListFinancialObligations::class)
            ->assertTableColumnStateSet('financial_status', 'Расчёт недоступен', $obligation);

        $filtered
            ->set('tableFilters.status.value', FinancialStatus::Outstanding->value)
            ->assertCountTableRecords(0)
            ->set('tableFilters.status.value', 'unexpected')
            ->assertCountTableRecords(0);

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
            ->assertTableColumnStateSet('amount_summary', '20.00 USD', $payment)
            ->assertTableActionExists('correctPayment', null, $payment);

        $history
            ->mountTableAction('correctPayment', $payment)
            ->assertFormFieldExists('reason')
            ->setTableActionData([
                'reason' => 'Исправлена сумма.',
                'idempotency_key' => 'history-correction',
            ])
            ->callMountedTableAction();

        $correction = FinancialLedgerEntry::query()
            ->where('corrects_ledger_entry_id', $payment->getKey())
            ->sole();
        $replayed = app(CorrectFinancialPayment::class)->handle(
            actor: $admin,
            original: $payment,
            reason: 'Исправлена сумма.',
            idempotencyKey: 'history-correction',
        );

        self::assertSame(-2000, $correction->settlement_amount_minor);
        self::assertSame($correction->getKey(), $replayed->getKey());
        Livewire::actingAs($admin)
            ->test(FinancialPaymentsRelationManager::class, [
                'ownerRecord' => $obligation,
                'pageClass' => ViewFinancialObligation::class,
            ])
            ->loadTable()
            ->assertTableColumnStateSet('amount_summary', '-20.00 USD', $correction);
        self::assertDatabaseHas('financial_ledger_entries', [
            'id' => $payment->getKey(),
            'amount_minor' => 2000,
        ]);
        self::assertStringContainsString('Расчёт по визиту', $component->html());
    }

    public function test_incompatible_ledger_currency_is_excluded_from_normal_status_filters(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $payment = $this->rawLedgerEntry($admin, $obligation, 'incompatible-status-payment', [
            'display_currency' => 'RUB',
        ]);
        $this->resolveFilamentContext($admin, $organization);

        $list = Livewire::actingAs($admin)
            ->test(ListFinancialObligations::class)
            ->assertTableColumnStateSet('financial_status', 'Расчёт недоступен', $obligation);

        foreach (FinancialStatus::cases() as $status) {
            $list
                ->set('tableFilters.status.value', $status->value)
                ->assertCountTableRecords(0);
        }

        Livewire::actingAs($admin)
            ->test(FinancialPaymentsRelationManager::class, [
                'ownerRecord' => $obligation,
                'pageClass' => ViewFinancialObligation::class,
            ])
            ->loadTable()
            ->assertTableColumnStateSet('amount_summary', '—', $payment)
            ->assertTableActionHidden('correctPayment', $payment);
    }

    public function test_overpayment_is_excluded_from_normal_status_filters(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $payment = $this->rawLedgerEntry($admin, $obligation, 'overpayment-status-payment', [
            'settlement_amount_minor' => 10100,
        ]);
        $this->resolveFilamentContext($admin, $organization);

        $list = Livewire::actingAs($admin)
            ->test(ListFinancialObligations::class)
            ->assertTableColumnStateSet('financial_status', 'Расчёт недоступен', $obligation);

        foreach (FinancialStatus::cases() as $status) {
            $list
                ->set('tableFilters.status.value', $status->value)
                ->assertCountTableRecords(0);
        }
    }

    public function test_malformed_legacy_currency_fails_closed_in_finance_list_and_detail(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        DB::table('financial_obligations')
            ->where('id', $obligation->getKey())
            ->update(['display_currency' => 'ZZZ']);
        $obligation->refresh();
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(ListFinancialObligations::class)
            ->assertTableColumnStateSet('amount_summary', '—', $obligation)
            ->assertTableColumnStateSet('financial_status', 'Расчёт недоступен', $obligation);

        Livewire::actingAs($admin)
            ->test(ViewFinancialObligation::class, ['record' => $obligation->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Расчёт недоступен. Проверьте историю оплат.');
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

    public function test_invalid_persisted_configuration_renders_unavailable_and_cannot_be_overwritten(): void
    {
        [$organization, $admin] = $this->financeFixture(singleCurrency: true);
        DB::table('organization_currency_configurations')
            ->where('organization_id', $organization->getKey())
            ->update(['base_currency' => 'ZZZ']);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(FinanceConfiguration::class)
            ->assertSuccessful()
            ->assertSet('configurationUnavailable', true)
            ->assertSee('Настройки валют недоступны');

        self::assertStringNotContainsString('Сохранить финансовые настройки', $component->html());
        try {
            app(SaveCurrencyConfiguration::class)->handle($admin, [
                'base_currency' => 'USD',
                'display_currency' => 'USD',
                'allowed_currencies' => ['USD'],
                'force_single_currency' => true,
                'rounding_mode' => 'half_up',
            ]);
            self::fail('A normal save must not overwrite a corrupt persisted configuration.');
        } catch (\Throwable $exception) {
            self::assertInstanceOf(ValidationException::class, $exception);
            self::assertSame('ZZZ', DB::table('organization_currency_configurations')
                ->where('organization_id', $organization->getKey())
                ->value('base_currency'));
        }
        self::assertSame('ZZZ', DB::table('organization_currency_configurations')
            ->where('organization_id', $organization->getKey())
            ->value('base_currency'));
    }

    public function test_save_currency_configuration_rejects_an_invalid_submitted_rate_without_mutating_current_configuration(): void
    {
        [$organization, $admin] = $this->financeFixture(singleCurrency: false);
        $version = DB::table('organization_currency_configurations')
            ->where('organization_id', $organization->getKey())
            ->value('version');

        try {
            app(SaveCurrencyConfiguration::class)->handle($admin, [
                'base_currency' => 'RUB',
                'display_currency' => 'USD',
                'allowed_currencies' => ['RUB', 'USD'],
                'force_single_currency' => false,
                'rounding_mode' => 'half_even',
                'rates' => [
                    ['source_currency' => 'ZZZ', 'target_currency' => 'RUB', 'rate' => '501'],
                    ['source_currency' => 'RUB', 'target_currency' => 'USD', 'rate' => '0.001996007984031936'],
                ],
            ]);
            self::fail('An invalid submitted exchange rate must be rejected.');
        } catch (ValidationException) {
            self::assertSame('500.000000000000000000', (string) DB::table('organization_exchange_rates')
                ->where('organization_id', $organization->getKey())
                ->where('source_currency', 'USD')
                ->where('target_currency', 'RUB')
                ->value('rate'));
            self::assertSame($version, DB::table('organization_currency_configurations')
                ->where('organization_id', $organization->getKey())
                ->value('version'));
            self::assertDatabaseMissing('organization_exchange_rates', [
                'organization_id' => $organization->getKey(),
                'source_currency' => 'ZZZ',
                'target_currency' => 'RUB',
                'rate' => '501',
            ]);
        }
    }

    public function test_save_currency_configuration_does_not_repair_a_missing_active_rate_as_a_normal_save(): void
    {
        [$organization, $admin] = $this->financeFixture(singleCurrency: false);
        DB::table('organization_exchange_rates')
            ->where('organization_id', $organization->getKey())
            ->where('source_currency', 'USD')
            ->where('target_currency', 'RUB')
            ->delete();
        $version = DB::table('organization_currency_configurations')
            ->where('organization_id', $organization->getKey())
            ->value('version');
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(FinanceConfiguration::class)
            ->assertSuccessful()
            ->assertSet('configurationUnavailable', true)
            ->assertSee('Настройки валют недоступны');

        try {
            app(SaveCurrencyConfiguration::class)->handle($admin, [
                'base_currency' => 'RUB',
                'display_currency' => 'USD',
                'allowed_currencies' => ['RUB', 'USD'],
                'force_single_currency' => false,
                'rounding_mode' => 'half_up',
                'rates' => [
                    ['source_currency' => 'USD', 'target_currency' => 'RUB', 'rate' => '500'],
                    ['source_currency' => 'RUB', 'target_currency' => 'USD', 'rate' => '0.002'],
                ],
            ]);
            self::fail('A normal save must not repair a corrupt active rate set.');
        } catch (ValidationException) {
            self::assertDatabaseMissing('organization_exchange_rates', [
                'organization_id' => $organization->getKey(),
                'source_currency' => 'USD',
                'target_currency' => 'RUB',
            ]);
            self::assertSame($version, DB::table('organization_currency_configurations')
                ->where('organization_id', $organization->getKey())
                ->value('version'));
        }
    }

    public function test_correction_action_fails_closed_for_malformed_ledger_currency_and_payment_metadata(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $payment = $this->rawLedgerEntry($admin, $obligation, 'malformed-correction-source', [
            'payment_currency' => 'ZZZ',
            'payment_method' => 'unsupported_method',
        ]);
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(FinancialPaymentsRelationManager::class, [
                'ownerRecord' => $obligation,
                'pageClass' => ViewFinancialObligation::class,
            ])
            ->loadTable()
            ->assertTableColumnStateSet('amount_summary', '—', $payment)
            ->assertTableActionHidden('correctPayment', $payment)
            ->assertSee('Способ оплаты недоступен');
    }

    public function test_booking_finance_logs_sanitized_metadata_for_expected_history_corruption(): void
    {
        [$organization, $admin, , $booking, $obligation] = $this->financeFixture(singleCurrency: true);
        $snapshots = $obligation->conversion_snapshots;
        unset($snapshots['display']);
        $obligation->forceFill(['conversion_snapshots' => $snapshots])->save();
        $this->resolveFilamentContext($admin, $organization);
        Log::spy();

        Livewire::actingAs($admin)
            ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Расчёт недоступен');

        Log::shouldHaveReceived('warning')
            ->with(
                'Booking finance reconciliation was unavailable for persisted history.',
                [
                    'organization_id' => $organization->getKey(),
                    'booking_id' => $booking->getKey(),
                    'obligation_id' => $obligation->getKey(),
                    'reason_code' => 'invalid_persisted_finance_history',
                ],
            )
            ->once();
    }

    public function test_booking_finance_does_not_convert_unexpected_errors_to_unavailable_state(): void
    {
        [$organization, $admin, , $booking] = $this->financeFixture(singleCurrency: true);
        DB::listen(static function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'financial_ledger_entries')) {
                throw new RuntimeException('database connection failed');
            }
        });
        $this->expectException(RuntimeException::class);
        app(GetBookingFinanceSummary::class)->handle($admin, $booking);
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

    public function test_view_only_finance_user_can_read_booking_detail_and_history_without_mutation_actions(): void
    {
        [$organization, $admin, , $booking, $obligation] = $this->financeFixture(singleCurrency: true);
        $payment = app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '20.00',
            currency: 'USD',
            paymentMethod: 'cash',
            occurredAt: CarbonImmutable::now('UTC'),
            note: null,
            receipt: null,
            idempotencyKey: 'view-only-history-payment',
        );
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $this->resolveFilamentContext($staff, $organization);

        Livewire::actingAs($staff)
            ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
            ->assertSuccessful()
            ->assertActionExists('openPayment')
            ->assertActionHidden('recordBookingPayment');

        Livewire::actingAs($staff)
            ->test(ViewFinancialObligation::class, ['record' => $obligation->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('recordPayment');

        Livewire::actingAs($staff)
            ->test(FinancialPaymentsRelationManager::class, [
                'ownerRecord' => $obligation,
                'pageClass' => ViewFinancialObligation::class,
            ])
            ->loadTable()
            ->assertTableActionHidden('correctPayment', $payment);
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

    public function test_initial_currency_configuration_form_is_seeded_from_the_local_service_currency(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $admin = User::factory()->forOrganization($organization)->create();
        Service::factory()->forOrganization($organization)->create([
            'price_minor' => 10000,
            'price_currency' => 'USD',
        ]);
        $this->resolveFilamentContext($admin, $organization);

        Livewire::actingAs($admin)
            ->test(FinanceConfiguration::class)
            ->assertSet('data.base_currency', 'USD')
            ->assertSet('data.display_currency', 'USD')
            ->assertSet('data.allowed_currencies', ['USD'])
            ->assertSet('data.force_single_currency', true)
            ->call('save');

        self::assertDatabaseHas('organization_currency_configurations', [
            'organization_id' => $organization->getKey(),
            'base_currency' => 'USD',
            'display_currency' => 'USD',
            'force_single_currency' => true,
        ]);
    }

    public function test_currency_configuration_form_normalizes_single_and_multi_currency_transitions(): void
    {
        $organization = Organization::factory()->create(['timezone' => 'Asia/Almaty']);
        $admin = User::factory()->forOrganization($organization)->create();
        $this->setOrganization($organization);
        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'USD',
            'allowed_currencies' => ['RUB', 'USD'],
            'force_single_currency' => false,
            'rounding_mode' => 'half_up',
            'rates' => [
                ['source_currency' => 'USD', 'target_currency' => 'RUB', 'rate' => '500'],
                ['source_currency' => 'RUB', 'target_currency' => 'USD', 'rate' => '0.002'],
            ],
        ]);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)->test(FinanceConfiguration::class);
        $component
            ->set('data.force_single_currency', true)
            ->assertSet('data.display_currency', 'RUB')
            ->assertSet('data.allowed_currencies', ['RUB'])
            ->set('data.base_currency', 'USD')
            ->assertSet('data.display_currency', 'USD')
            ->assertSet('data.allowed_currencies', ['USD'])
            ->call('save');

        self::assertSame('USD', OrganizationCurrencyConfiguration::query()->where('organization_id', $organization->getKey())->firstOrFail()->base_currency->value);
        self::assertSame(['USD'], DB::table('organization_allowed_currencies')->where('organization_id', $organization->getKey())->pluck('currency')->all());
        self::assertSame(2, DB::table('organization_exchange_rates')->where('organization_id', $organization->getKey())->count());

        $component = Livewire::actingAs($admin)->test(FinanceConfiguration::class);
        $component
            ->set('data.force_single_currency', false)
            ->assertSet('data.display_currency', 'USD')
            ->assertSet('data.allowed_currencies', ['RUB', 'USD'])
            ->set('data.base_currency', 'RUB')
            ->assertSet('data.base_currency', 'RUB')
            ->assertSet('data.allowed_currencies', ['RUB', 'USD'])
            ->set('data.display_currency', 'RUB')
            ->assertSet('data.display_currency', 'RUB')
            ->set('data.allowed_currencies', ['USD'])
            ->assertSet('data.allowed_currencies', ['RUB', 'USD'])
            ->assertSet('data.force_single_currency', false)
            ->call('save');

        self::assertSame('RUB', OrganizationCurrencyConfiguration::query()->where('organization_id', $organization->getKey())->firstOrFail()->base_currency->value);
        self::assertSame('RUB', OrganizationCurrencyConfiguration::query()->where('organization_id', $organization->getKey())->firstOrFail()->display_currency->value);

        app(SaveCurrencyConfiguration::class)->handle($admin, [
            'base_currency' => 'RUB',
            'display_currency' => 'RUB',
            'allowed_currencies' => ['RUB', 'USD'],
            'force_single_currency' => false,
            'rounding_mode' => 'half_up',
            'rates' => [
                ['source_currency' => 'USD', 'target_currency' => 'RUB', 'rate' => '501'],
            ],
        ]);

        self::assertDatabaseHas('organization_exchange_rates', [
            'organization_id' => $organization->getKey(),
            'source_currency' => 'USD',
            'target_currency' => 'RUB',
            'rate' => '501',
        ]);
        self::assertDatabaseMissing('organization_exchange_rates', [
            'organization_id' => $organization->getKey(),
            'source_currency' => 'RUB',
            'target_currency' => 'USD',
        ]);
    }

    public function test_finance_relationship_filters_preload_bounded_local_options_safe_labels_and_search(): void
    {
        [$organization, $admin, $client, $booking] = $this->financeFixture(singleCurrency: true);
        $service = $booking->service;
        $unnamedClient = Client::factory()->forOrganization($organization)->create(['full_name' => null]);
        $blankClient = Client::factory()->forOrganization($organization)->create(['full_name' => '   ']);
        $otherOrganization = Organization::factory()->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create(['full_name' => 'Чужой клиент']);
        $otherService = Service::factory()->forOrganization($otherOrganization)->create(['name' => 'Чужая услуга']);
        $this->resolveFilamentContext($admin, $organization);

        $component = Livewire::actingAs($admin)
            ->test(ListFinancialObligations::class)
            ->assertSuccessful();

        foreach ([
            'client' => [$client->full_name, $otherClient->getKey()],
            'service' => [$service->name, $otherService->getKey()],
        ] as $filterName => [$search, $foreignKey]) {
            $filter = $component->instance()->getTable()->getFilter($filterName);
            self::assertNotNull($filter);
            self::assertTrue($filter->isPreloaded());
            self::assertSame(50, $filter->getOptionsLimit());
            $component->instance()->getTableFiltersForm();
            $field = $filter->getSchema()->getFlatFields()['value'];
            $options = $filter->getOptionsFromRelationship($field);

            self::assertIsArray($options);
            self::assertArrayHasKey($filterName === 'client' ? $client->getKey() : $service->getKey(), $options);
            self::assertArrayNotHasKey($foreignKey, $options);
            $searchResults = $filter->getSearchResultsFromRelationship($field, $search);

            self::assertArrayHasKey(
                $filterName === 'client' ? $client->getKey() : $service->getKey(),
                $searchResults,
            );

            if ($filterName === 'client') {
                self::assertSame($client->full_name, $options[$client->getKey()]);
                self::assertSame('Имя не указано', $options[$unnamedClient->getKey()]);
                self::assertSame('Имя не указано', $options[$blankClient->getKey()]);
                self::assertSame($client->full_name, $searchResults[$client->getKey()]);
            }
        }
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
        $otherClient = Client::factory()->forOrganization($organization)->create(['full_name' => 'Другой клиент']);
        $otherBooking = Booking::factory()
            ->forClient($otherClient)
            ->forSpecialist($booking->specialist)
            ->forService($booking->service)
            ->create([
                'starts_at' => CarbonImmutable::create(2026, 8, 19, 9, 0, 0, 'UTC'),
                'ends_at' => CarbonImmutable::create(2026, 8, 19, 10, 0, 0, 'UTC'),
                'blocking_ends_at' => CarbonImmutable::create(2026, 8, 19, 10, 0, 0, 'UTC'),
            ]);
        app(CompleteBooking::class)->handle($admin, $otherBooking);
        $otherObligation = FinancialObligation::query()->where('booking_id', $otherBooking->getKey())->sole();
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
            ->assertSee('filters');

        $financeUrl = app(FinancePresentation::class)->clientFinanceUrl($client);
        $this->get($financeUrl)
            ->assertOk()
            ->assertSee('Иван Петров');

        parse_str((string) parse_url($financeUrl, PHP_URL_QUERY), $query);
        Livewire::withQueryParams($query)
            ->actingAs($admin)
            ->test(ListFinancialObligations::class)
            ->assertCanSeeTableRecords([$obligation])
            ->assertCanNotSeeTableRecords([$otherObligation]);

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
            ->assertTableColumnStateSet('amount_summary', '100.00 USD', $entry)
            ->assertSee('Тестовая оплата')
            ->assertTableActionHidden('correctPayment', $entry);

        $this->assertCorrectionRejected($admin, $entry, 'reject-fake-gateway-correction');
    }

    public function test_finance_presentation_marks_zero_manual_payment_amount_unavailable_without_mutation_action(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $payment = $this->inMemoryLedgerEntry($obligation, 0);
        $this->resolveFilamentContext($admin, $organization);

        self::assertSame('—', app(FinancePresentation::class)->ledgerPaymentAmount($payment));
        self::assertFalse(FinancePaymentActions::correction()->record($payment)->isVisible());
    }

    public function test_finance_presentation_marks_decimal_raw_manual_payment_amount_unavailable(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $payment = $this->inMemoryLedgerEntry($obligation, '20.5');
        $this->resolveFilamentContext($admin, $organization);

        self::assertSame('—', app(FinancePresentation::class)->ledgerPaymentAmount($payment));
        self::assertFalse(FinancePaymentActions::correction()->record($payment)->isVisible());
    }

    public function test_direct_correction_rejects_forbidden_ledger_and_parent_states_without_side_effects(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $payment = $this->manualPayment($admin, $obligation, 'direct-correction-original');
        $correction = app(CorrectFinancialPayment::class)->handle(
            actor: $admin,
            original: $payment,
            reason: 'Первичное исправление.',
            idempotencyKey: 'direct-correction-accepted',
        );
        $this->assertCorrectionRejected($admin, $correction, 'reject-correction-row');
        $this->assertCorrectionRejected($admin, $payment, 'reject-already-corrected');

        [, $malformedAdmin, , , $malformedObligation] = $this->financeFixture(singleCurrency: true);
        $malformedPayment = $this->rawLedgerEntry($malformedAdmin, $malformedObligation, 'direct-correction-malformed', [
            'payment_method' => 'unsupported_method',
        ]);
        $this->assertCorrectionRejected($malformedAdmin, $malformedPayment, 'reject-malformed-ledger');

        [, $incompatibleAdmin, , , $incompatibleObligation] = $this->financeFixture(singleCurrency: true);
        $incompatiblePayment = $this->rawLedgerEntry($incompatibleAdmin, $incompatibleObligation, 'direct-correction-incompatible', [
            'display_currency' => 'RUB',
        ]);
        $this->assertCorrectionRejected($incompatibleAdmin, $incompatiblePayment, 'reject-incompatible-ledger');

        [, $invalidParentAdmin, , , $invalidParentObligation] = $this->financeFixture(singleCurrency: true);
        $invalidParentPayment = $this->manualPayment($invalidParentAdmin, $invalidParentObligation, 'direct-correction-invalid-parent');
        $snapshots = $invalidParentObligation->conversion_snapshots;
        unset($snapshots['display']);
        DB::table('financial_obligations')
            ->where('id', $invalidParentObligation->getKey())
            ->update(['conversion_snapshots' => json_encode($snapshots, JSON_THROW_ON_ERROR)]);
        $this->assertCorrectionRejected($invalidParentAdmin, $invalidParentPayment, 'reject-invalid-parent');

        self::assertSame(2, DB::table('financial_ledger_entries')
            ->where('organization_id', $organization->getKey())
            ->count());
    }

    public function test_direct_correction_rejects_cross_organization_entries_without_side_effects(): void
    {
        [$organization, $admin, , , $obligation] = $this->financeFixture(singleCurrency: true);
        $payment = $this->manualPayment($admin, $obligation, 'direct-correction-cross-organization');
        $otherOrganization = Organization::factory()->create(['timezone' => 'UTC']);
        $otherAdmin = User::factory()->forOrganization($otherOrganization)->create();
        $this->setOrganization($otherOrganization);
        $ledgerCount = DB::table('financial_ledger_entries')
            ->where('organization_id', $organization->getKey())
            ->count();

        try {
            app(CorrectFinancialPayment::class)->handle(
                actor: $otherAdmin,
                original: $payment,
                reason: 'Чужая организация.',
                idempotencyKey: 'reject-cross-organization-correction',
            );
            self::fail('Cross-organization correction must be rejected at the application boundary.');
        } catch (AuthorizationException) {
            self::assertSame($ledgerCount, DB::table('financial_ledger_entries')
                ->where('organization_id', $organization->getKey())
                ->count());
            self::assertDatabaseMissing('finance_idempotency_keys', [
                'organization_id' => $otherOrganization->getKey(),
                'idempotency_key' => 'reject-cross-organization-correction',
            ]);
        }
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

    private function manualPayment(User $admin, FinancialObligation $obligation, string $idempotencyKey): FinancialLedgerEntry
    {
        return app(RecordManualPayment::class)->handle(
            actor: $admin,
            obligation: $obligation,
            amount: '20.00',
            currency: 'USD',
            paymentMethod: 'cash',
            occurredAt: CarbonImmutable::now('UTC'),
            note: null,
            receipt: null,
            idempotencyKey: $idempotencyKey,
        );
    }

    /** @param array<string, mixed> $overrides */
    private function rawLedgerEntry(
        User $admin,
        FinancialObligation $obligation,
        string $idempotencyKey,
        array $overrides = [],
    ): FinancialLedgerEntry {
        $attributes = [
            'organization_id' => $obligation->organization_id,
            'obligation_id' => $obligation->getKey(),
            'entry_type' => 'manual_payment',
            'source' => 'crm',
            'amount_minor' => 2000,
            'currency' => 'USD',
            'payment_amount_minor' => 2000,
            'payment_currency' => 'USD',
            'base_amount_minor' => 2000,
            'base_currency' => 'USD',
            'display_amount_minor' => 2000,
            'display_currency' => 'USD',
            'settlement_amount_minor' => 2000,
            'settlement_currency' => 'USD',
            'conversion_snapshot' => null,
            'payment_method' => 'cash',
            'occurred_at' => CarbonImmutable::now('UTC'),
            'note' => null,
            'actor_user_id' => $admin->getKey(),
            'provider_reference' => null,
            'idempotency_key' => 'raw:'.$idempotencyKey,
            'corrects_ledger_entry_id' => null,
            'created_at' => now(),
        ];
        $id = DB::table('financial_ledger_entries')->insertGetId([...$attributes, ...$overrides]);

        return FinancialLedgerEntry::query()->findOrFail($id);
    }

    private function inMemoryLedgerEntry(FinancialObligation $obligation, mixed $paymentAmountMinor): FinancialLedgerEntry
    {
        $entry = new FinancialLedgerEntry;
        $entry->setRawAttributes([
            'entry_type' => 'manual_payment',
            'payment_currency' => 'USD',
            'payment_amount_minor' => $paymentAmountMinor,
        ]);
        $entry->setRelation('obligation', $obligation);

        return $entry;
    }

    private function assertCorrectionRejected(User $admin, FinancialLedgerEntry $entry, string $idempotencyKey): void
    {
        $ledgerCount = DB::table('financial_ledger_entries')
            ->where('organization_id', $entry->getRawOriginal('organization_id'))
            ->count();

        try {
            app(CorrectFinancialPayment::class)->handle(
                actor: $admin,
                original: $entry,
                reason: 'Недопустимое исправление.',
                idempotencyKey: $idempotencyKey,
            );
            self::fail('The forbidden correction must be rejected.');
        } catch (ValidationException) {
            self::assertSame($ledgerCount, DB::table('financial_ledger_entries')
                ->where('organization_id', $entry->getRawOriginal('organization_id'))
                ->count());
            self::assertDatabaseMissing('finance_idempotency_keys', [
                'organization_id' => $entry->getRawOriginal('organization_id'),
                'idempotency_key' => $idempotencyKey,
            ]);
        }
    }
}
