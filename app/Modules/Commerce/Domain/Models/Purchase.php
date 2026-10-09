<?php

namespace App\Modules\Commerce\Domain\Models;

use App\Modules\Commerce\Domain\Enums\PurchaseStatus;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $organization_id
 * @property int $client_id
 * @property PurchaseStatus $status
 * @property int $total_amount_minor
 * @property CurrencyCode $currency
 * @property array<string, mixed> $purchase_snapshot
 * @property Carbon|null $paid_at
 * @property Carbon|null $refunded_at
 */
#[Fillable([])]
class Purchase extends Model
{
    protected $table = 'commerce_purchases';

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return HasMany<PurchaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    /** @return HasOne<FinancialObligation, $this> */
    public function obligation(): HasOne
    {
        return $this->hasOne(FinancialObligation::class, 'purchase_id');
    }

    /**
     * @param  Builder<Purchase>  $query
     * @return Builder<Purchase>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return [
            'status' => PurchaseStatus::class,
            'currency' => CurrencyCode::class,
            'total_amount_minor' => 'integer',
            'purchase_snapshot' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
