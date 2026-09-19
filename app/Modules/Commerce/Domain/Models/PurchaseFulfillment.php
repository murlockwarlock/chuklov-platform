<?php

namespace App\Modules\Commerce\Domain\Models;

use App\Modules\Commerce\Domain\Enums\CommerceFulfillmentStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([])]
class PurchaseFulfillment extends Model
{
    protected $table = 'commerce_purchase_fulfillments';

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<PurchaseItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class, 'purchase_item_id');
    }

    /** @return HasMany<FulfillmentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FulfillmentEvent::class, 'fulfillment_id');
    }

    protected function casts(): array
    {
        return [
            'status' => CommerceFulfillmentStatus::class,
            'attempts' => 'integer',
            'provider_metadata' => 'array',
            'fulfilled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
