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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

final class FinanceReconciliationProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_projection_covers_canonical_same_currency_statuses_and_correction(): void
    {
        [$organization, $obligation] = $this->obligationFixture();

        $this->assertCanonicalState($organization, $obligation, FinancialStatus::Outstanding->value);
        $payment = $this->ledger($obligation, 2500, 2500);
        $this->assertCanonicalState($organization, $obligation, FinancialStatus::PartiallyPaid->value);
        $this->ledger($obligation, 7500, 7500);
        $this->assertCanonicalState($organization, $obligation, FinancialStatus::Settled->value);
        $this->ledger($obligation, -2500, -2500, 'correction', $payment->getKey());
        $this->assertCanonicalState($organization, $obligation, FinancialStatus::PartiallyPaid->value);
    }

    /** @return iterable<string, array{string}> */
    public static function noncanonicalCurrencyCases(): iterable
    {
        foreach (['currency', 'base_currency', 'display_currency', 'payment_currency', 'settlement_currency'] as $attribute) {
            yield 'obligation '.$attribute => ['obligation:'.$attribute];
            yield 'ledger '.$attribute => ['ledger:'.$attribute];
        }
    }

    #[DataProvider('noncanonicalCurrencyCases')]
    public function test_noncanonical_persisted_currencies_fail_closed(string $case): void
    {
        [$organization, $obligation] = $this->obligationFixture();
        [$model, $attribute] = explode(':', $case, 2);

        if ($model === 'obligation') {
            DB::table('financial_obligations')
                ->where('id', $obligation->getKey())
                ->update([$attribute => 'usd']);
        } else {
            $entry = $this->ledger($obligation, 2500, 2500);
            DB::table('financial_ledger_entries')
                ->where('id', $entry->getKey())
                ->update([$attribute => 'usd']);
        }

        $this->assertCanonicalState($organization, $obligation, null);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSnapshotCases(): iterable
    {
        yield 'missing display snapshot' => ['missing_display'];
        yield 'numeric rate' => ['numeric_rate'];
        yield 'trailing-zero rate' => ['trailing_zero_rate'];
        yield 'numeric minor amount' => ['numeric_minor'];
        yield 'leading zero minor amount' => ['leading_zero_minor'];
        yield 'negative zero minor amount' => ['negative_zero_minor'];
        yield 'invalid snapshot currency' => ['snapshot_currency'];
        yield 'whitespace-wrapped snapshot currency' => ['snapshot_whitespace_currency'];
        yield 'invalid rounding mode' => ['rounding_mode'];
        yield 'wrong source scale' => ['source_scale'];
        yield 'wrong target scale' => ['target_scale'];
        yield 'same currency non-unit rate' => ['same_currency_rate'];
        yield 'same currency mismatched amount' => ['same_currency_amount'];
    }

    #[DataProvider('invalidSnapshotCases')]
    public function test_snapshot_contract_fails_closed_for_noncanonical_or_nonidentity_values(string $case): void
    {
        [$organization, $obligation] = $this->obligationFixture();
        $snapshots = $obligation->conversion_snapshots;
        $updates = [];

        switch ($case) {
            case 'missing_display':
                unset($snapshots['display']);
                break;
            case 'numeric_rate':
                $snapshots['base']['rate'] = 1;
                break;
            case 'trailing_zero_rate':
                $snapshots['base']['rate'] = '1.0';
                break;
            case 'numeric_minor':
                $snapshots['base']['source_amount_minor'] = 10000;
                break;
            case 'leading_zero_minor':
                $snapshots['base']['target_amount_minor'] = '010000';
                break;
            case 'negative_zero_minor':
                $snapshots['base']['target_amount_minor'] = '-0';
                break;
            case 'snapshot_currency':
                $snapshots['base']['source_currency'] = 'usd';
                break;
            case 'snapshot_whitespace_currency':
                $snapshots['base']['source_currency'] = 'USD ';
                break;
            case 'rounding_mode':
                $snapshots['base']['rounding_mode'] = 'invalid';
                break;
            case 'source_scale':
                $snapshots['base']['source_scale'] = 0;
                break;
            case 'target_scale':
                $snapshots['base']['target_scale'] = 0;
                break;
            case 'same_currency_rate':
                $snapshots['base']['rate'] = '2';
                $snapshots['base']['target_amount_minor'] = '20000';
                $updates['base_amount_minor'] = 20000;
                break;
            case 'same_currency_amount':
                $snapshots['base']['target_amount_minor'] = '9999';
                $updates['base_amount_minor'] = 9999;
                break;
        }

        DB::table('financial_obligations')
            ->where('id', $obligation->getKey())
            ->update([
                ...$updates,
                'conversion_snapshots' => json_encode($snapshots, JSON_THROW_ON_ERROR),
            ]);

        $this->assertCanonicalState($organization, $obligation, null);
    }

    public function test_sqlite_projection_rejects_negative_aggregate_and_overpayment(): void
    {
        [$negativeOrganization, $negativeObligation] = $this->obligationFixture();
        $payment = $this->ledger($negativeObligation, 2500, 2500);
        $this->ledger($negativeObligation, -5000, -5000, 'correction', $payment->getKey());
        $this->assertCanonicalState($negativeOrganization, $negativeObligation, null);

        [$overpaidOrganization, $overpaidObligation] = $this->obligationFixture();
        $this->ledger($overpaidObligation, 10001, 10001);
        $this->assertCanonicalState($overpaidOrganization, $overpaidObligation, null);
    }

    /** @return array{Organization, FinancialObligation} */
    private function obligationFixture(): array
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $money = Money::ofMinor(10000, 'USD');
        $snapshot = [
            'source_amount_minor' => '10000',
            'source_currency' => 'USD',
            'target_amount_minor' => '10000',
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
            'price_snapshot' => ['amount_minor' => '10000'],
            'conversion_snapshots' => ['base' => $snapshot, 'display' => $snapshot],
            'creation_key' => 'projection-'.$organization->getKey(),
        ])->save();

        return [$organization, $obligation];
    }

    private function ledger(
        FinancialObligation $obligation,
        int $amountMinor,
        int $settlementAmountMinor,
        string $entryType = 'manual_payment',
        ?int $corrects = null,
    ): FinancialLedgerEntry {
        $entry = new FinancialLedgerEntry;
        $entry->forceFill([
            'organization_id' => $obligation->organization_id,
            'obligation_id' => $obligation->getKey(),
            'entry_type' => $entryType,
            'source' => 'crm',
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'payment_amount_minor' => $amountMinor,
            'payment_currency' => 'USD',
            'base_amount_minor' => $amountMinor,
            'base_currency' => 'USD',
            'display_amount_minor' => $amountMinor,
            'display_currency' => 'USD',
            'settlement_amount_minor' => $settlementAmountMinor,
            'settlement_currency' => 'USD',
            'conversion_snapshot' => null,
            'payment_method' => $entryType === 'correction' ? null : 'cash',
            'occurred_at' => now(),
            'idempotency_key' => 'projection-ledger-'.uniqid('', true),
            'corrects_ledger_entry_id' => $corrects,
        ])->save();

        return $entry;
    }

    private function assertCanonicalState(
        Organization $organization,
        FinancialObligation $obligation,
        ?string $expectedStatus,
    ): void {
        $phpStatus = null;

        try {
            $phpStatus = app(ReconcileFinancialObligation::class)
                ->handle($organization->getKey(), $obligation->getKey())
                ->status
                ->value;
        } catch (InvalidArgumentException|UnexpectedValueException) {
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
