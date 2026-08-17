<?php

namespace App\Modules\AI\Domain\Models;

use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $provider_name
 * @property string $display_name
 * @property bool $is_enabled
 * @property ProviderHealthStatus $health_status
 * @property int|null $credential_id
 * @property array<string, mixed> $options
 * @property CarbonInterface|null $last_checked_at
 * @property string|null $last_health_error
 * @property-read Organization $organization
 * @property-read OrganizationCredential|null $credential
 * @property-read HasMany<AiModelConfiguration, $this> $models
 */
#[Fillable([
    'organization_id',
    'provider_name',
    'display_name',
    'is_enabled',
    'health_status',
    'credential_id',
    'options',
    'last_checked_at',
    'last_health_error',
])]
class AiProviderConfiguration extends Model
{
    protected $attributes = [
        'is_enabled' => true,
        'health_status' => 'unknown',
        'options' => '[]',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<OrganizationCredential, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(OrganizationCredential::class, 'credential_id');
    }

    /** @return HasMany<AiModelConfiguration, $this> */
    public function models(): HasMany
    {
        return $this->hasMany(AiModelConfiguration::class, 'provider_config_id');
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
            'health_status' => ProviderHealthStatus::class,
            'options' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }
}
