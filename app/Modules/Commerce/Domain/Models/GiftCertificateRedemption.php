<?php

namespace App\Modules\Commerce\Domain\Models;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\FinancialLedgerEntry;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $certificate_id
 * @property int $holder_client_id
 * @property int $financial_obligation_id
 * @property int $financial_ledger_entry_id
 * @property int $amount_minor
 * @property CurrencyCode $currency
 * @property string $idempotency_key
 * @property Carbon $occurred_at
 */
#[Fillable([])]
class GiftCertificateRedemption extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'gift_certificate_redemptions';

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<GiftCertificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(GiftCertificate::class, 'certificate_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'holder_client_id');
    }

    /** @return BelongsTo<FinancialObligation, $this> */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(FinancialObligation::class, 'financial_obligation_id');
    }

    /** @return BelongsTo<FinancialLedgerEntry, $this> */
    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialLedgerEntry::class, 'financial_ledger_entry_id');
    }

    /** @return HasOne<GiftCertificateMovement, $this> */
    public function movement(): HasOne
    {
        return $this->hasOne(GiftCertificateMovement::class, 'redemption_id')
            ->where('movement_type', 'redeemed');
    }

    /**
     * @param  Builder<GiftCertificateRedemption>  $query
     * @return Builder<GiftCertificateRedemption>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'amount_minor' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
