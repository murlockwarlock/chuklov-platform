<?php

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class FulfillmentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'commerce_fulfillment_events';

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<PurchaseFulfillment, $this> */
    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(PurchaseFulfillment::class, 'fulfillment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
