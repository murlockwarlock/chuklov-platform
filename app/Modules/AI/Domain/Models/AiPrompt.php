<?php

namespace App\Modules\AI\Domain\Models;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property AiCapability $capability
 * @property int|null $active_version_id
 * @property-read Organization $organization
 * @property-read AiPromptVersion|null $activeVersion
 * @property-read HasMany<AiPromptVersion, $this> $versions
 */
#[Fillable([
    'organization_id',
    'key',
    'name',
    'description',
    'capability',
    'active_version_id',
])]
class AiPrompt extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiPromptVersion, $this> */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(AiPromptVersion::class, 'active_version_id');
    }

    /** @return HasMany<AiPromptVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AiPromptVersion::class, 'prompt_id')->orderBy('version', 'desc');
    }

    /** @return HasOne<AiPromptVersion, $this> */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(AiPromptVersion::class, 'prompt_id')->latestOfMany('version');
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
            'capability' => AiCapability::class,
        ];
    }
}
