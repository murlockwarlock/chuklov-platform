<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Domain\ValueObjects\MoneyConversionSnapshot;
use App\Modules\Referrals\Domain\Enums\ReferralRewardCategory;
use App\Modules\Referrals\Domain\Models\ReferralRewardConversionSnapshot;
use App\Modules\Referrals\Domain\Models\ReferralRewardLedgerEntry;
use UnexpectedValueException;

final class RecordReferralRewardConversionSnapshot
{
    public function handle(
        ReferralRewardLedgerEntry $entry,
        MoneyConversionSnapshot $snapshot,
        string $purpose,
    ): ReferralRewardConversionSnapshot {
        if ($entry->reward_category !== ReferralRewardCategory::ServiceCredit
            || (int) $entry->amount_minor !== (int) $snapshot->targetAmountMinor
            || $entry->getRawOriginal('currency') !== $snapshot->targetCurrency->value) {
            throw new UnexpectedValueException('The referral reward conversion snapshot does not match the ledger entry.');
        }

        $record = new ReferralRewardConversionSnapshot;
        $record->forceFill([
            'organization_id' => $entry->organization_id,
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
        $record->save();

        return $record->refresh();
    }
}
