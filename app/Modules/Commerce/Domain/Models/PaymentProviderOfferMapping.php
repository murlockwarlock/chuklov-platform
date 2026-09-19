<?php

namespace App\Modules\Commerce\Domain\Models;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class PaymentProviderOfferMapping extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<PaymentProviderOfferMapping>  $query
     * @return Builder<PaymentProviderOfferMapping>
     */
    public function scopeActiveFor(
        Builder $query,
        int $organizationId,
        string $gateway,
        string $sellableType,
        int $sellableId,
        string $currency,
    ): Builder {
        return $query
            ->where('organization_id', $organizationId)
            ->where('gateway', $gateway)
            ->where('sellable_type', $sellableType)
            ->where('sellable_id', $sellableId)
            ->where('currency', $currency)
            ->where('is_active', true);
    }

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'is_active' => 'boolean',
            'provider_metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
