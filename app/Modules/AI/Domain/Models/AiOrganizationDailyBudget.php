<?php

namespace App\Modules\AI\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property CarbonInterface $usage_date
 * @property int $spent_minor_units
 * @property int $reserved_minor_units
 * @property-read Organization $organization
 */
#[Fillable([
    'organization_id',
    'usage_date',
    'spent_minor_units',
    'reserved_minor_units',
])]
class AiOrganizationDailyBudget extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function setUsageDateAttribute(mixed $value): void
    {
        $this->attributes['usage_date'] = $value instanceof CarbonInterface
            ? $value->toDateString()
            : Carbon::parse($value)->toDateString();
    }

    protected function casts(): array
    {
        return [
            'usage_date' => 'immutable_date',
            'spent_minor_units' => 'integer',
            'reserved_minor_units' => 'integer',
        ];
    }
}
