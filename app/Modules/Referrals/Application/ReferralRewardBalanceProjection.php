<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\ValueObjects\ReferralRewardBalance;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class ReferralRewardBalanceProjection
{
    public function __construct(private readonly OrganizationContext $context) {}

    /** @return list<ReferralRewardBalance> */
    public function forClient(Client|int $client): array
    {
        $clientId = $client instanceof Client ? (int) $client->getKey() : $client;
        $organizationId = $this->context->id();
        $totals = [];

        $ledgerRows = DB::table('referral_reward_ledger_entries')
            ->where('organization_id', $organizationId)
            ->where('beneficiary_client_id', $clientId)
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

            if ($entryType === ReferralRewardLedgerEntryType::Earned->value) {
                $totals[$key]['earned'] = $this->add($totals[$key]['earned'], $row->total_minor, $currency);
            } elseif ($entryType === ReferralRewardLedgerEntryType::Reversed->value) {
                $totals[$key]['reversed'] = $this->add($totals[$key]['reversed'], $row->total_minor, $currency);
            }
        }

        $payoutRows = DB::table('referral_payout_requests')
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
            ),
            $totals,
        ));
    }

    public function forCurrency(Client|int $client, CurrencyCode $currency): ReferralRewardBalance
    {
        foreach ($this->forClient($client) as $balance) {
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
        );
    }

    /** @return array{currency: CurrencyCode, earned: Money, reversed: Money, pending: Money, paid: Money} */
    private function emptyTotals(CurrencyCode $currency): array
    {
        return [
            'currency' => $currency,
            'earned' => Money::zero($currency),
            'reversed' => Money::zero($currency),
            'pending' => Money::zero($currency),
            'paid' => Money::zero($currency),
        ];
    }

    private function add(Money $current, mixed $amount, CurrencyCode $currency): Money
    {
        return $current->add(Money::ofMinor(BigInteger::of((string) $amount)->toString(), $currency));
    }
}
