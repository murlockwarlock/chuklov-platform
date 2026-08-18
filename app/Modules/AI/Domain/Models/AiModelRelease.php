<?php

namespace App\Modules\AI\Domain\Models;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $model_config_id
 * @property int $release_number
 * @property string $status
 * @property string $provider_name
 * @property string $model_name
 * @property list<string> $capabilities
 * @property array<string, mixed> $pricing_snapshot
 * @property CarbonInterface|null $activated_at
 * @property int|null $activated_by_user_id
 * @property-read Organization $organization
 * @property-read AiModelConfiguration $modelConfiguration
 * @property-read User|null $activatedBy
 */
#[Fillable([
    'organization_id',
    'model_config_id',
    'release_number',
    'status',
    'provider_name',
    'model_name',
    'capabilities',
    'pricing_snapshot',
    'activated_at',
    'activated_by_user_id',
])]
class AiModelRelease extends Model
{
    protected $attributes = [
        'status' => 'active',
        'capabilities' => '[]',
        'pricing_snapshot' => '[]',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiModelConfiguration, $this> */
    public function modelConfiguration(): BelongsTo
    {
        return $this->belongsTo(AiModelConfiguration::class, 'model_config_id');
    }

    /** @return BelongsTo<User, $this> */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    public function getPricingSnapshot(): AiPricingSnapshot
    {
        return AiPricingSnapshot::fromArray($this->pricing_snapshot ?? []);
    }

    public function supportsModality(AiModelModality $modality): bool
    {
        $capabilities = $this->getAttribute('capabilities');

        return is_array($capabilities)
            && in_array($modality->value, $capabilities, true);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'pricing_snapshot' => 'array',
            'activated_at' => 'datetime',
            'release_number' => 'integer',
        ];
    }
}
