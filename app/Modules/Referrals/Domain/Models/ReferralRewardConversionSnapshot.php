<?php

namespace App\Modules\Referrals\Domain\Models;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Finance\Domain\ValueObjects\MoneyConversionSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([])]
class ReferralRewardConversionSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Referral reward conversion snapshots are immutable.');
        });
        static::deleting(static function (): void {
            throw new LogicException('Referral reward conversion snapshots are immutable.');
        });
    }

    /** @return BelongsTo<ReferralRewardLedgerEntry, $this> */
    public function rewardEntry(): BelongsTo
    {
        return $this->belongsTo(ReferralRewardLedgerEntry::class, 'referral_reward_ledger_entry_id');
    }

    public function toValueObject(): MoneyConversionSnapshot
    {
        $effectiveAt = $this->getRawOriginal('effective_at');

        return new MoneyConversionSnapshot(
            sourceAmountMinor: (string) $this->getRawOriginal('source_amount_minor'),
            sourceCurrency: CurrencyCode::from((string) $this->getRawOriginal('source_currency')),
            targetAmountMinor: (string) $this->getRawOriginal('target_amount_minor'),
            targetCurrency: CurrencyCode::from((string) $this->getRawOriginal('target_currency')),
            rate: (string) $this->getRawOriginal('rate'),
            rateId: $this->rate_id === null ? null : (int) $this->rate_id,
            rateVersion: $this->rate_version === null ? null : (int) $this->rate_version,
            effectiveAt: $effectiveAt === null ? null : CarbonImmutable::parse((string) $effectiveAt),
            roundingMode: FinancialRoundingMode::from((string) $this->getRawOriginal('rounding_mode')),
            sourceScale: (int) $this->source_scale,
            targetScale: (int) $this->target_scale,
        );
    }

    protected function casts(): array
    {
        return [
            'source_currency' => CurrencyCode::class,
            'target_currency' => CurrencyCode::class,
            'source_amount_minor' => 'integer',
            'target_amount_minor' => 'integer',
            'rate' => 'decimal:18',
            'rate_version' => 'integer',
            'effective_at' => 'datetime',
            'rounding_mode' => FinancialRoundingMode::class,
            'source_scale' => 'integer',
            'target_scale' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
