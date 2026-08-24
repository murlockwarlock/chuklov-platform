<?php

namespace App\Modules\Referrals\Domain\Models;

use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class ReferralConversionObservation extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ReferralRelationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(ReferralRelationship::class, 'referral_relationship_id');
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

    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
