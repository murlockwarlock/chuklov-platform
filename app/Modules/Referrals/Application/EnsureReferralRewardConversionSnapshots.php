<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Application\CurrencyConfigurationService;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Referrals\Domain\Enums\ReferralRewardCategory;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EnsureReferralRewardConversionSnapshots
{
    public function __construct(private readonly CurrencyConfigurationService $configuration) {}

    public function handle(): int
    {
        if (! Schema::hasTable('referral_reward_conversion_snapshots')) {
            return 0;
        }

        $created = 0;
        ReferralRewardLedgerEntry::query()
            ->where('reward_category', ReferralRewardCategory::ServiceCredit->value)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('referral_reward_conversion_snapshots')
                    ->whereColumn(
                        'referral_reward_conversion_snapshots.referral_reward_ledger_entry_id',
                        'referral_reward_ledger_entries.id',
                    )
                    ->whereColumn(
                        'referral_reward_conversion_snapshots.organization_id',
                        'referral_reward_ledger_entries.organization_id',
                    );
            })
            ->orderBy('id')
            ->get()
            ->each(function (ReferralRewardLedgerEntry $entry) use (&$created): void {
                $organizationId = (int) $entry->organization_id;
                $source = Money::ofMinor($entry->amount_minor, $entry->currency);
                $target = $this->configuration->configuration($organizationId)->base_currency;
                $snapshot = $this->configuration->convert($organizationId, $source, $target);
                $purpose = $entry->entry_type === ReferralRewardLedgerEntryType::Earned
                    ? 'legacy_service_credit_earning'
                    : 'legacy_service_credit_normalization';

                $inserted = DB::table('referral_reward_conversion_snapshots')->insertOrIgnore([
                    'organization_id' => $organizationId,
                    'referral_reward_ledger_entry_id' => $entry->getKey(),
                    'purpose' => $purpose,
                    'source_amount_minor' => $snapshot->sourceAmountMinor,
                    'source_currency' => $snapshot->sourceCurrency->value,
                    'target_amount_minor' => $snapshot->targetAmountMinor,
                    'target_currency' => $snapshot->targetCurrency->value,
                    'rate' => $snapshot->rate,
                    'rate_id' => $snapshot->rateId,
                    'rate_version' => $snapshot->rateVersion,
                    'effective_at' => $snapshot->effectiveAt,
                    'rounding_mode' => $snapshot->roundingMode->value,
                    'source_scale' => $snapshot->sourceScale,
                    'target_scale' => $snapshot->targetScale,
                    'created_at' => now(),
                ]);

                $created += $inserted;
            });

        return $created;
    }
}
