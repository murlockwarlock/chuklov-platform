<?php

namespace App\Modules\Referrals\Domain\Models;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Referrals\Domain\Enums\ReferralRewardLedgerEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property ReferralRewardLedgerEntryType $entry_type
 * @property CurrencyCode $currency
 * @property int $amount_minor
 * @property int $beneficiary_client_id
 * @property int $referred_client_id
 * @property int $referral_relationship_id
 * @property int $referral_commercial_evidence_id
 * @property string|null $reason
 * @property Carbon $occurred_at
 * @property-read Client|null $referred
 */
#[Fillable([])]
class ReferralRewardLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new LogicException('Referral reward ledger entries are append-only.');
        });
        static::deleting(static function (): void {
            throw new LogicException('Referral reward ledger entries are append-only.');
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'beneficiary_client_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'referred_client_id');
    }

    /** @return BelongsTo<ReferralRelationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(ReferralRelationship::class, 'referral_relationship_id');
    }

    /** @return BelongsTo<ReferralCommercialEvidence, $this> */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(ReferralCommercialEvidence::class, 'referral_commercial_evidence_id');
    }

    /** @return BelongsTo<FinancialObligation, $this> */
    public function financialObligation(): BelongsTo
    {
        return $this->belongsTo(FinancialObligation::class, 'financial_obligation_id');
    }

    /** @return BelongsTo<FinancialLedgerEntry, $this> */
    public function financialLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialLedgerEntry::class, 'financial_ledger_entry_id');
    }

    /** @return BelongsTo<ReferralRewardProgramVersion, $this> */
    public function programVersion(): BelongsTo
    {
        return $this->belongsTo(ReferralRewardProgramVersion::class, 'reward_program_version_id');
    }

    /** @return BelongsTo<ReferralRewardLedgerEntry, $this> */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    protected function casts(): array
    {
        return [
            'entry_type' => ReferralRewardLedgerEntryType::class,
            'currency' => CurrencyCode::class,
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
