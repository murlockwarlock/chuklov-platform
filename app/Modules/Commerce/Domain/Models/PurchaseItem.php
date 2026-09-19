<?php

namespace App\Modules\Commerce\Domain\Models;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class PurchaseItem extends Model
{
    protected $table = 'commerce_purchase_items';

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

    /** @return HasOne<PurchaseFulfillment, $this> */
    public function fulfillment(): HasOne
    {
        return $this->hasOne(PurchaseFulfillment::class, 'purchase_item_id');
    }

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'quantity' => 'integer',
            'amount_minor' => 'integer',
            'product_snapshot' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
