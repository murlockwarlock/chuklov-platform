<?php

namespace App\Modules\AI\Domain\Models;

use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\ModelLifecycleStatus;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $provider_config_id
 * @property string $model_name
 * @property string $display_name
 * @property bool $is_enabled
 * @property ModelLifecycleStatus $lifecycle_status
 * @property list<string> $capabilities
 * @property array<string, mixed> $pricing_snapshot
 * @property int $failover_priority
 * @property int|null $active_release_id
 * @property-read Organization $organization
 * @property-read AiProviderConfiguration|null $providerConfiguration
 * @property-read AiModelRelease|null $activeRelease
 * @property-read HasMany<AiModelRelease, $this> $releases
 */
#[Fillable([
    'organization_id',
    'provider_config_id',
    'model_name',
    'display_name',
    'is_enabled',
    'lifecycle_status',
    'capabilities',
    'pricing_snapshot',
    'failover_priority',
    'active_release_id',
])]
class AiModelConfiguration extends Model
{
    protected $attributes = [
        'is_enabled' => true,
        'lifecycle_status' => 'active',
        'capabilities' => '[]',
        'pricing_snapshot' => '[]',
        'failover_priority' => 1,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiProviderConfiguration, $this> */
    public function providerConfiguration(): BelongsTo
    {
        return $this->belongsTo(AiProviderConfiguration::class, 'provider_config_id');
    }

    /** @return BelongsTo<AiModelRelease, $this> */
    public function activeRelease(): BelongsTo
    {
        return $this->belongsTo(AiModelRelease::class, 'active_release_id');
    }

    /** @return HasMany<AiModelRelease, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(AiModelRelease::class, 'model_config_id')->orderBy('release_number', 'desc');
    }

    public function getPricingSnapshot(): AiPricingSnapshot
    {
        return AiPricingSnapshot::fromArray($this->pricing_snapshot ?? []);
    }

    public function supportsCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? [], true);
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

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'lifecycle_status' => ModelLifecycleStatus::class,
            'capabilities' => 'array',
            'pricing_snapshot' => 'array',
            'failover_priority' => 'integer',
        ];
    }
}
