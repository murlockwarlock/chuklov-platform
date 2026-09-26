<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Enums\ReferralRewardCategory;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use App\Modules\Referrals\Domain\ValueObjects\ReferralRewardBalance;
use Brick\Math\BigInteger;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class ReferralRewardBalanceProjection
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function availableServiceCreditQuery(string $obligationTable): Builder
    {
        $ledgerTable = (new ReferralRewardLedgerEntry)->getTable();

        return DB::table($ledgerTable)
            ->selectRaw("COALESCE(SUM(CASE
                WHEN entry_type IN ('earned', 'manual_credit', 'restored') THEN amount_minor
                WHEN entry_type IN ('reversed', 'redeemed') THEN -amount_minor
                ELSE 0
            END), 0)")
            ->whereColumn($ledgerTable.'.organization_id', $obligationTable.'.organization_id')
            ->whereColumn($ledgerTable.'.beneficiary_client_id', $obligationTable.'.client_id')
            ->where($ledgerTable.'.reward_category', ReferralRewardCategory::ServiceCredit->value)
            ->whereColumn($ledgerTable.'.currency', $obligationTable.'.settlement_currency');
    }

    /** @return list<ReferralRewardBalance> */
    public function forClient(Client|int $client, ?ReferralRewardCategory $category = null): array
    {
        $clientId = $client instanceof Client ? (int) $client->getKey() : $client;
        $organizationId = $this->context->id();
        $totals = [];

        $ledgerQuery = DB::table('referral_reward_ledger_entries')
            ->where('organization_id', $organizationId)
            ->where('beneficiary_client_id', $clientId);

        if ($category !== null) {
            $ledgerQuery->where('reward_category', $category->value);
        }

        $ledgerRows = $ledgerQuery
            ->select('currency', 'entry_type', DB::raw('SUM(amount_minor) AS total_minor'))
            ->groupBy('currency', 'entry_type')
            ->get();

        foreach ($ledgerRows as $row) {
            $currency = CurrencyCode::tryFrom((string) $row->currency);

            if ($currency === null) {
                throw new UnexpectedValueException('The reward ledger currency is invalid.');
            }

            $key = $currency->value;
            $totals[$key] ??= $this->emptyTotals($currency);
            $entryType = (string) $row->entry_type;

            if (in_array($entryType, [
                ReferralRewardLedgerEntryType::Earned->value,
                ReferralRewardLedgerEntryType::ManualCredit->value,
            ], true)) {
                $totals[$key]['earned'] = $this->add($totals[$key]['earned'], $row->total_minor, $currency);
            } elseif ($entryType === ReferralRewardLedgerEntryType::Reversed->value) {
                $totals[$key]['reversed'] = $this->add($totals[$key]['reversed'], $row->total_minor, $currency);
            } elseif ($entryType === ReferralRewardLedgerEntryType::Redeemed->value) {
                $totals[$key]['redeemed'] = $this->add($totals[$key]['redeemed'], $row->total_minor, $currency);
            } elseif ($entryType === ReferralRewardLedgerEntryType::Restored->value) {
                $totals[$key]['restored'] = $this->add($totals[$key]['restored'], $row->total_minor, $currency);
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
            $amount = Money::ofMinor((string) $row->total_minor, $currency);

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

    public function forCurrency(
        Client|int $client,
        CurrencyCode $currency,
        ?ReferralRewardCategory $category = null,
    ): ReferralRewardBalance {
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

    private function add(Money $current, mixed $amount, CurrencyCode $currency): Money
    {
        return $current->add(Money::ofMinor(BigInteger::of((string) $amount)->toString(), $currency));
    }
}
