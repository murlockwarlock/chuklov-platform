<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Enums\ReferralRewardCategory;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Models\ReferralRewardConversionSnapshot;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Referrals\Domain\ValueObjects\ReferralRewardBalance;
use Brick\Math\BigInteger;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class ReferralRewardBalanceProjection
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly CurrencyConfigurationService $configuration,
    ) {}

    public function availableServiceCreditQuery(string $obligationTable): Builder
    {
        $ledgerTable = (new ReferralRewardLedgerEntry)->getTable();
        $snapshotTable = (new ReferralRewardConversionSnapshot)->getTable();

        return DB::table($ledgerTable)
            ->leftJoin($snapshotTable, function (JoinClause $join) use ($ledgerTable, $snapshotTable): void {
                $join->on($snapshotTable.'.organization_id', '=', $ledgerTable.'.organization_id')
                    ->on($snapshotTable.'.referral_reward_ledger_entry_id', '=', $ledgerTable.'.id');
            })
            ->selectRaw($this->availableServiceCreditSql())
            ->whereColumn($ledgerTable.'.organization_id', $obligationTable.'.organization_id')
            ->whereColumn($ledgerTable.'.beneficiary_client_id', $obligationTable.'.client_id')
            ->where($ledgerTable.'.reward_category', ReferralRewardCategory::ServiceCredit->value)
            ->whereRaw($this->normalizedCurrencyFilterSql());
    }

    /** @return list<ReferralRewardBalance> */
    public function forClient(Client|int $client, ?ReferralRewardCategory $category = null): array
    {
        $clientId = $client instanceof Client ? (int) $client->getKey() : $client;
        $organizationId = $this->context->id();
        $ledgerTable = (new ReferralRewardLedgerEntry)->getTable();
        $snapshotTable = (new ReferralRewardConversionSnapshot)->getTable();

        $ledgerQuery = DB::table($ledgerTable)
            ->leftJoin($snapshotTable, function (JoinClause $join) use ($ledgerTable, $snapshotTable): void {
                $join->on($snapshotTable.'.organization_id', '=', $ledgerTable.'.organization_id')
                    ->on($snapshotTable.'.referral_reward_ledger_entry_id', '=', $ledgerTable.'.id');
            })
            ->where($ledgerTable.'.organization_id', $organizationId)
            ->where($ledgerTable.'.beneficiary_client_id', $clientId);

        if ($category !== null) {
            $ledgerQuery->where($ledgerTable.'.reward_category', $category->value);
        }

        $ledgerRows = $ledgerQuery
            ->select($ledgerTable.'.entry_type')
            ->selectRaw($this->normalizedCurrencySql().' AS normalized_currency')
            ->selectRaw('SUM('.$this->normalizedAmountSql().') AS total_minor')
            ->groupBy($ledgerTable.'.entry_type')
            ->groupBy('normalized_currency')
            ->get();
        $totals = [];
        $baseCurrency = $category === ReferralRewardCategory::ServiceCredit
            ? $this->baseCurrency()
            : null;

        foreach ($ledgerRows as $row) {
            $currency = CurrencyCode::tryFrom((string) $row->normalized_currency);

            if ($currency === null) {
                throw new UnexpectedValueException('The reward ledger currency is invalid.');
            }

            if ($category === ReferralRewardCategory::ServiceCredit && $currency !== $baseCurrency) {
                throw new UnexpectedValueException('A ServiceCredit entry is not normalized to organization base currency.');
            }

            $key = $currency->value;
            $totals[$key] ??= $this->emptyTotals($currency);
            $entryType = (string) $row->entry_type;
            $amount = $this->addMoney($row->total_minor, $currency);

            if (in_array($entryType, [
                ReferralRewardLedgerEntryType::Earned->value,
                ReferralRewardLedgerEntryType::ManualCredit->value,
            ], true)) {
                $totals[$key]['earned'] = $totals[$key]['earned']->add($amount);
            } elseif ($entryType === ReferralRewardLedgerEntryType::Reversed->value) {
                $totals[$key]['reversed'] = $totals[$key]['reversed']->add($amount);
            } elseif ($entryType === ReferralRewardLedgerEntryType::Redeemed->value) {
                $totals[$key]['redeemed'] = $totals[$key]['redeemed']->add($amount);
            } elseif ($entryType === ReferralRewardLedgerEntryType::Restored->value) {
                $totals[$key]['restored'] = $totals[$key]['restored']->add($amount);
            }
        }

        $payoutRows = $category === ReferralRewardCategory::ServiceCredit
            ? collect()
            : DB::table('referral_payout_requests')
                ->where('organization_id', $organizationId)
                ->where('beneficiary_client_id', $clientId)
                ->whereIn('status', [
                    ReferralPayoutRequestStatus::Requested->value,
                    ReferralPayoutRequestStatus::Approved->value,
                    ReferralPayoutRequestStatus::Paid->value,
                ])
                ->select('currency', 'status', DB::raw('SUM(amount_minor) AS total_minor'))
                ->groupBy('currency', 'status')
                ->get();

        foreach ($payoutRows as $row) {
            $currency = CurrencyCode::tryFrom((string) $row->currency);

            if ($currency === null) {
                throw new UnexpectedValueException('The payout currency is invalid.');
            }

            $key = $currency->value;
            $totals[$key] ??= $this->emptyTotals($currency);
            $amount = $this->addMoney($row->total_minor, $currency);

            if (in_array((string) $row->status, [
                ReferralPayoutRequestStatus::Requested->value,
                ReferralPayoutRequestStatus::Approved->value,
            ], true)) {
                $totals[$key]['pending'] = $totals[$key]['pending']->add($amount);
            } elseif ((string) $row->status === ReferralPayoutRequestStatus::Paid->value) {
                $totals[$key]['paid'] = $totals[$key]['paid']->add($amount);
            }
        }

        ksort($totals);

        return array_values(array_map(
            static fn (array $total): ReferralRewardBalance => new ReferralRewardBalance(
                currency: $total['currency'],
                earned: $total['earned'],
                reversed: $total['reversed'],
                pending: $total['pending'],
                paid: $total['paid'],
                redeemed: $total['redeemed'],
                restored: $total['restored'],
            ),
            $totals,
        ));
    }

    public function serviceCredit(Client|int $client): ReferralRewardBalance
    {
        return $this->forCurrency($client, $this->baseCurrency(), ReferralRewardCategory::ServiceCredit);
    }

    public function accountingMoney(ReferralRewardLedgerEntry $entry): Money
    {
        if ($entry->reward_category !== ReferralRewardCategory::ServiceCredit) {
            return Money::ofMinor($entry->amount_minor, $entry->currency);
        }

        $snapshot = $this->conversionSnapshot($entry);
        if ($snapshot instanceof ReferralRewardConversionSnapshot) {
            return Money::ofMinor($snapshot->target_amount_minor, $snapshot->target_currency);
        }

        $baseCurrency = $this->baseCurrency();
        if ($entry->currency !== $baseCurrency) {
            throw new UnexpectedValueException('A ServiceCredit entry has no immutable base-currency conversion.');
        }

        return Money::ofMinor($entry->amount_minor, $baseCurrency);
    }

    public function conversionSnapshot(ReferralRewardLedgerEntry $entry): ?ReferralRewardConversionSnapshot
    {
        if ($entry->relationLoaded('conversionSnapshot')) {
            $snapshot = $entry->getRelation('conversionSnapshot');

            return $snapshot instanceof ReferralRewardConversionSnapshot ? $snapshot : null;
        }

        return ReferralRewardConversionSnapshot::query()
            ->where('organization_id', $this->context->id())
            ->where('referral_reward_ledger_entry_id', $entry->getKey())
            ->first();
    }

    public function forCurrency(
        Client|int $client,
        CurrencyCode $currency,
        ?ReferralRewardCategory $category = null,
    ): ReferralRewardBalance {
        if ($category === ReferralRewardCategory::ServiceCredit && $currency !== $this->baseCurrency()) {
            return new ReferralRewardBalance(
                currency: $currency,
                earned: Money::zero($currency),
                reversed: Money::zero($currency),
                pending: Money::zero($currency),
                paid: Money::zero($currency),
                redeemed: Money::zero($currency),
                restored: Money::zero($currency),
            );
        }

        foreach ($this->forClient($client, $category) as $balance) {
            if ($balance->currency === $currency) {
                return $balance;
            }
        }

        return new ReferralRewardBalance(
            currency: $currency,
            earned: Money::zero($currency),
            reversed: Money::zero($currency),
            pending: Money::zero($currency),
            paid: Money::zero($currency),
            redeemed: Money::zero($currency),
            restored: Money::zero($currency),
        );
    }

    /** @return array{currency: CurrencyCode, earned: Money, reversed: Money, pending: Money, paid: Money, redeemed: Money, restored: Money} */
    private function emptyTotals(CurrencyCode $currency): array
    {
        return [
            'currency' => $currency,
            'earned' => Money::zero($currency),
            'reversed' => Money::zero($currency),
            'pending' => Money::zero($currency),
            'paid' => Money::zero($currency),
            'redeemed' => Money::zero($currency),
            'restored' => Money::zero($currency),
        ];
    }

    private function addMoney(mixed $amount, CurrencyCode $currency): Money
    {
        return Money::ofMinor(BigInteger::of((string) $amount)->toString(), $currency);
    }

    private function baseCurrency(): CurrencyCode
    {
        return $this->configuration->configuration($this->context->id())->base_currency;
    }

    /** @return literal-string */
    private function availableServiceCreditSql(): string
    {
        $amount = 'COALESCE(referral_reward_conversion_snapshots.target_amount_minor, referral_reward_ledger_entries.amount_minor)';

        return "COALESCE(SUM(CASE
            WHEN referral_reward_ledger_entries.entry_type IN ('earned', 'manual_credit', 'restored') THEN {$amount}
            WHEN referral_reward_ledger_entries.entry_type IN ('reversed', 'redeemed') THEN -{$amount}
            ELSE 0
        END), 0)";
    }

    /** @return literal-string */
    private function normalizedCurrencyFilterSql(): string
    {
        return 'COALESCE(referral_reward_conversion_snapshots.target_currency, referral_reward_ledger_entries.currency) = (
            SELECT base_currency
            FROM organization_currency_configurations
            WHERE organization_currency_configurations.organization_id = referral_reward_ledger_entries.organization_id
            LIMIT 1
        )';
    }

    /** @return literal-string */
    private function normalizedCurrencySql(): string
    {
        return "CASE WHEN referral_reward_ledger_entries.reward_category = 'service_credit' THEN COALESCE(referral_reward_conversion_snapshots.target_currency, referral_reward_ledger_entries.currency) ELSE referral_reward_ledger_entries.currency END";
    }

    /** @return literal-string */
    private function normalizedAmountSql(): string
    {
        return "CASE WHEN referral_reward_ledger_entries.reward_category = 'service_credit' THEN COALESCE(referral_reward_conversion_snapshots.target_amount_minor, referral_reward_ledger_entries.amount_minor) ELSE referral_reward_ledger_entries.amount_minor END";
    }
}
