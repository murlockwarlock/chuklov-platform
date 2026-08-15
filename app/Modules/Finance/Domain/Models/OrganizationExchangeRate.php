<?php

namespace App\Modules\Finance\Domain\Models;

use App\Models\User;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property CurrencyCode $source_currency
 * @property CurrencyCode $target_currency
 * @property string $rate
 * @property int $version
 * @property Carbon $effective_at
 * @property Carbon $created_at
 */
#[Fillable([])]
class OrganizationExchangeRate extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'source_currency' => CurrencyCode::class,
            'target_currency' => CurrencyCode::class,
            'rate' => 'decimal:18',
            'version' => 'integer',
            'effective_at' => 'datetime',
        ];
    }
}
