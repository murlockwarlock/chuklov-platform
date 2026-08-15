<?php

namespace App\Modules\Finance\Domain\Models;

use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\Enums\FinancialRoundingMode;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property CurrencyCode $base_currency
 * @property CurrencyCode $display_currency
 * @property bool $force_single_currency
 * @property FinancialRoundingMode $rounding_mode
 * @property int $version
 * @property Carbon $created_at
 */
#[Fillable([])]
class OrganizationCurrencyConfiguration extends Model
{
    public $timestamps = true;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'base_currency' => CurrencyCode::class,
            'display_currency' => CurrencyCode::class,
            'force_single_currency' => 'boolean',
            'rounding_mode' => FinancialRoundingMode::class,
            'version' => 'integer',
        ];
    }
}
