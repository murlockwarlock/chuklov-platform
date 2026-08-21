<?php

namespace Tests\Feature;

use App\Modules\Finance\Application\ListFinancialObligationsForCrm;
use App\Modules\Finance\Application\ReconcileFinancialObligation;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\Enums\FinancialStatus;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

final class FinanceReconciliationParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_php_and_sql_projection_agree_for_outstanding_partial_settled_and_correction(): void
    {
        [$organization, $obligation] = $this->obligationFixture();

        $this->assertParity($organization, $obligation, FinancialStatus::Outstanding->value);
        $payment = $this->ledger($obligation, 2500, 2500);
        $this->assertParity($organization, $obligation, FinancialStatus::PartiallyPaid->value);
        $this->ledger($obligation, 7500, 7500);
        $this->assertParity($organization, $obligation, FinancialStatus::Settled->value);
        $this->ledger($obligation, -2500, -2500, 'correction', $payment->getKey());
        $this->assertParity($organization, $obligation, FinancialStatus::PartiallyPaid->value);
    }

    public function test_lowercase_and_whitespace_normalizable_currencies_have_the_same_valid_result(): void
    {
        [$organization, $obligation] = $this->obligationFixture();
        $snapshots = $obligation->conversion_snapshots;

        foreach (['base', 'display'] as $role) {
            $snapshots[$role]['source_currency'] = ' usd ';
            $snapshots[$role]['target_currency'] = ' usd ';
        }

        DB::table('financial_obligations')
            ->where('id', $obligation->getKey())
            ->update([
                'currency' => 'usd',
                'base_currency' => 'usd',
                'display_currency' => 'usd',
                'payment_currency' => 'usd',
                'settlement_currency' => 'usd',
                'conversion_snapshots' => json_encode($snapshots, JSON_THROW_ON_ERROR),
            ]);

        $this->assertParity($organization, $obligation, FinancialStatus::Outstanding->value);
    }

    #[DataProvider('roundingCases')]
    public function test_php_and_sql_projection_agree_for_jpy_to_decimal_rounding(
        string $roundingMode,
        int $targetAmountMinor,
    ): void {
        [$organization, $obligation] = $this->obligationFixture(
            settlementCurrency: 'JPY',
            targetCurrency: 'USD',
            settlementAmountMinor: 1,
            rate: '0.005',
            roundingMode: $roundingMode,
        );

        self::assertSame($targetAmountMinor, $obligation->display_amount_minor);
        $this->assertParity($organization, $obligation, FinancialStatus::Outstanding->value);
    }

    #[DataProvider('reverseRoundingCases')]
    public function test_php_and_sql_projection_agree_for_decimal_to_jpy_rounding(
        string $roundingMode,
        int $targetAmountMinor,
    ): void {
        [$organization, $obligation] = $this->obligationFixture(
            settlementCurrency: 'USD',
            targetCurrency: 'JPY',
            settlementAmountMinor: 1,
            rate: '50',
            roundingMode: $roundingMode,
        );

        self::assertSame($targetAmountMinor, $obligation->display_amount_minor);
        $this->assertParity($organization, $obligation, FinancialStatus::Outstanding->value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCases(): iterable
    {
        yield 'missing base snapshot' => ['missing_base_snapshot'];
        yield 'missing display snapshot' => ['missing_display_snapshot'];
        yield 'malformed source amount' => ['malformed_source_amount'];
        yield 'malformed target amount' => ['malformed_target_amount'];
        yield 'zero rate' => ['zero_rate'];
        yield 'negative rate' => ['negative_rate'];
        yield 'malformed rate' => ['malformed_rate'];
        yield 'floating point JSON rate' => ['floating_point_rate'];
        yield 'wrong source currency' => ['wrong_source_currency'];
        yield 'wrong target currency' => ['wrong_target_currency'];
        yield 'wrong immutable target amount' => ['wrong_target_amount'];
        yield 'invalid rounding mode' => ['invalid_rounding_mode'];
        yield 'invalid obligation currency' => ['currency'];
        yield 'invalid obligation base currency' => ['base_currency'];
        yield 'invalid obligation display currency' => ['display_currency'];
        yield 'invalid obligation payment currency' => ['payment_currency'];
        yield 'invalid obligation settlement currency' => ['settlement_currency'];
        yield 'zero obligation amount' => ['zero_obligation_amount'];
        yield 'malformed obligation amount' => ['malformed_obligation_amount'];
        yield 'incompatible ledger settlement currency' => ['ledger_settlement_currency'];
        yield 'incompatible ledger base currency' => ['ledger_base_currency'];
        yield 'incompatible ledger display currency' => ['ledger_display_currency'];
        yield 'invalid ledger currency' => ['ledger_currency'];
        yield 'invalid ledger payment currency' => ['ledger_payment_currency'];
        yield 'zero ledger settlement' => ['zero_ledger_settlement'];
        yield 'malformed ledger settlement amount' => ['malformed_ledger_settlement_amount'];
        yield 'negative applied balance' => ['negative_applied'];
        yield 'overpayment' => ['overpayment'];
    }

    #[DataProvider('invalidCases')]
    public function test_php_and_sql_projection_reject_the_same_invalid_persisted_record(string $case): void
    {
        [$organization, $obligation] = $this->obligationFixture();
        $snapshots = $obligation->conversion_snapshots;

        switch ($case) {
            case 'missing_base_snapshot':
                unset($snapshots['base']);
                break;
            case 'missing_display_snapshot':
                unset($snapshots['display']);
                break;
            case 'malformed_source_amount':
                $snapshots['base']['source_amount_minor'] = 'not-an-int';
                break;
            case 'malformed_target_amount':
                $snapshots['display']['target_amount_minor'] = 'not-an-int';
                break;
            case 'zero_rate':
                $snapshots['base']['rate'] = '0';
                break;
            case 'negative_rate':
                $snapshots['base']['rate'] = '-1';
                break;
            case 'malformed_rate':
                $snapshots['base']['rate'] = 'not-a-rate';
                break;
            case 'floating_point_rate':
                $snapshots['base']['rate'] = 0.5;
                $snapshots['base']['target_amount_minor'] = '5000';
                break;
            case 'wrong_source_currency':
                $snapshots['base']['source_currency'] = 'RUB';
                break;
            case 'wrong_target_currency':
                $snapshots['base']['target_currency'] = 'RUB';
                break;
            case 'wrong_target_amount':
                $snapshots['base']['target_amount_minor'] = '999';
                break;
            case 'invalid_rounding_mode':
                $snapshots['base']['rounding_mode'] = 'invalid';
                break;
        }

        if (in_array($case, ['missing_base_snapshot', 'missing_display_snapshot', 'malformed_source_amount', 'malformed_target_amount', 'zero_rate', 'negative_rate', 'malformed_rate', 'floating_point_rate', 'wrong_source_currency', 'wrong_target_currency', 'wrong_target_amount', 'invalid_rounding_mode'], true)) {
            $updates = ['conversion_snapshots' => json_encode($snapshots, JSON_THROW_ON_ERROR)];

            if ($case === 'floating_point_rate') {
                $updates['base_amount_minor'] = 5000;
            }

            DB::table('financial_obligations')
                ->where('id', $obligation->getKey())
                ->update($updates);
        }

        if (in_array($case, ['currency', 'base_currency', 'display_currency', 'payment_currency', 'settlement_currency'], true)) {
            DB::table('financial_obligations')
                ->where('id', $obligation->getKey())
                ->update([$case => 'ZZZ']);
        }

        if ($case === 'zero_obligation_amount') {
            DB::table('financial_obligations')->where('id', $obligation->getKey())->update(['settlement_amount_minor' => 0]);
        }

        if ($case === 'malformed_obligation_amount') {
            DB::table('financial_obligations')->where('id', $obligation->getKey())->update(['amount_minor' => 1.5]);
        }

        if (str_starts_with($case, 'ledger_') || in_array($case, ['zero_ledger_settlement', 'malformed_ledger_settlement_amount', 'negative_applied', 'overpayment'], true)) {
            $entry = $this->ledger($obligation, 2500, 2500);
            $ledgerField = match ($case) {
                'ledger_settlement_currency' => 'settlement_currency',
                'ledger_base_currency' => 'base_currency',
                'ledger_display_currency' => 'display_currency',
                'ledger_currency' => 'currency',
                'ledger_payment_currency' => 'payment_currency',
                default => null,
            };
            $value = match ($case) {
                'zero_ledger_settlement' => ['settlement_amount_minor' => 0],
                'malformed_ledger_settlement_amount' => ['settlement_amount_minor' => 1.5],
                'negative_applied' => ['settlement_amount_minor' => -100],
                'overpayment' => ['settlement_amount_minor' => 10001],
                default => [$ledgerField => in_array($case, ['ledger_currency', 'ledger_payment_currency'], true) ? 'ZZZ' : 'RUB'],
            };
            DB::table('financial_ledger_entries')->where('id', $entry->getKey())->update($value);
        }

        $this->assertParity($organization, $obligation, null);
    }

    /** @return array<string, array{string, int}> */
    public static function roundingCases(): array
    {
        return [
            'half up' => [FinancialRoundingMode::HalfUp->value, 1],
            'half even' => [FinancialRoundingMode::HalfEven->value, 0],
            'down' => [FinancialRoundingMode::Down->value, 0],
        ];
    }

    /** @return array<string, array{string, int}> */
    public static function reverseRoundingCases(): array
    {
        return self::roundingCases();
    }

    /** @return array{Organization, FinancialObligation} */
    private function obligationFixture(
        string $settlementCurrency = 'USD',
        string $targetCurrency = 'USD',
        int $settlementAmountMinor = 10000,
        string $rate = '1',
        string $roundingMode = 'half_up',
    ): array {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $rounding = FinancialRoundingMode::fromMixed($roundingMode);
        $target = Money::ofMinor($settlementAmountMinor, $settlementCurrency)
            ->convert($targetCurrency, $rate, $rounding);
        $snapshot = [
            'source_amount_minor' => (string) $settlementAmountMinor,
            'source_currency' => $settlementCurrency,
            'target_amount_minor' => $target->minorUnitsString(),
            'target_currency' => $targetCurrency,
            'rate' => $rate,
            'rate_id' => null,
            'rate_version' => null,
            'effective_at' => null,
            'rounding_mode' => $roundingMode,
            'source_scale' => Money::ofMinor($settlementAmountMinor, $settlementCurrency)->scale(),
            'target_scale' => $target->scale(),
        ];
        $obligation = new FinancialObligation;
        $obligation->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'booking_id' => $booking->getKey(),
            'service_id' => $service->getKey(),
            'amount_minor' => $settlementAmountMinor,
            'currency' => $settlementCurrency,
            'base_amount_minor' => $target->minorUnits(),
            'base_currency' => $targetCurrency,
            'display_amount_minor' => $target->minorUnits(),
            'display_currency' => $targetCurrency,
            'payment_amount_minor' => $settlementAmountMinor,
            'payment_currency' => $settlementCurrency,
            'settlement_amount_minor' => $settlementAmountMinor,
            'settlement_currency' => $settlementCurrency,
            'price_snapshot' => ['amount_minor' => $settlementAmountMinor],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => 'parity-'.$organization->getKey(),
        ])->save();

        return [$organization, $obligation];
    }

    private function ledger(FinancialObligation $obligation, int $amountMinor, int $settlementAmountMinor, string $entryType = 'manual_payment', ?int $corrects = null): FinancialLedgerEntry
    {
        $entry = new FinancialLedgerEntry;
        $currency = (string) $obligation->getRawOriginal('settlement_currency');
        $targetCurrency = (string) $obligation->getRawOriginal('display_currency');
        $entry->forceFill([
            'organization_id' => $obligation->organization_id,
            'obligation_id' => $obligation->getKey(),
            'entry_type' => $entryType,
            'source' => $entryType === 'correction' ? 'crm' : 'crm',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'payment_amount_minor' => $amountMinor,
            'payment_currency' => $currency,
            'base_amount_minor' => $amountMinor,
            'base_currency' => $targetCurrency,
            'display_amount_minor' => $amountMinor,
            'display_currency' => $targetCurrency,
            'settlement_amount_minor' => $settlementAmountMinor,
            'settlement_currency' => $currency,
            'conversion_snapshot' => null,
            'payment_method' => $entryType === 'correction' ? null : 'cash',
            'occurred_at' => now(),
            'idempotency_key' => 'parity-ledger-'.uniqid('', true),
            'corrects_ledger_entry_id' => $corrects,
        ])->save();

        return $entry;
    }

    private function assertParity(Organization $organization, FinancialObligation $obligation, ?string $expectedStatus): void
    {
        $phpStatus = null;

        try {
            $phpStatus = app(ReconcileFinancialObligation::class)
                ->handle($organization->getKey(), $obligation->getKey())
                ->status
                ->value;
        } catch (UnexpectedValueException) {
            $phpStatus = null;
        }

        self::assertSame($expectedStatus, $phpStatus);
        $list = app(ListFinancialObligationsForCrm::class);

        foreach (FinancialStatus::cases() as $status) {
            $matches = $list->applyStatusFilter(
                $list->query($organization->getKey()),
                $status->value,
            )->whereKey($obligation->getKey())->count();

            self::assertSame($expectedStatus === $status->value ? 1 : 0, $matches, $status->value);
        }
    }
}
