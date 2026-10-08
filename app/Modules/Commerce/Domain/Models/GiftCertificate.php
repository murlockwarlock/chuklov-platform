<?php

namespace App\Modules\Commerce\Domain\Models;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $purchase_id
 * @property int $purchase_item_id
 * @property int $purchase_fulfillment_id
 * @property int $purchaser_client_id
 * @property int|null $current_holder_client_id
 * @property int $original_amount_minor
 * @property CurrencyCode $currency
 * @property Carbon|null $issued_at
 */
#[Fillable([])]
class GiftCertificate extends Model
{
    protected $table = 'gift_certificates';

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<PurchaseItem, $this> */
    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }

    /** @return BelongsTo<PurchaseFulfillment, $this> */
    public function purchaseFulfillment(): BelongsTo
    {
        return $this->belongsTo(PurchaseFulfillment::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function purchaser(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'purchaser_client_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function currentHolder(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'current_holder_client_id');
    }

    /** @return HasMany<GiftCertificateClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(GiftCertificateClaim::class, 'certificate_id');
    }

    /** @return HasMany<GiftCertificateMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(GiftCertificateMovement::class, 'certificate_id');
    }

    /** @return HasMany<GiftCertificateRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(GiftCertificateRedemption::class, 'certificate_id');
    }

    /**
     * @param  Builder<GiftCertificate>  $query
     * @return Builder<GiftCertificate>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'original_amount_minor' => 'integer',
            'issued_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
