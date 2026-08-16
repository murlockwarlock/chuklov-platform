<?php

namespace App\Modules\AI\Domain\Models;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property AiCapability $capability
 * @property int|null $prompt_id
 * @property-read Organization $organization
 * @property-read AiPrompt|null $prompt
 * @property-read HasMany<AiEvalCase, $this> $cases
 * @property-read HasMany<AiEvalRun, $this> $runs
 */
#[Fillable([
    'organization_id',
    'key',
    'name',
    'description',
    'capability',
    'prompt_id',
])]
class AiEvalSuite extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiPrompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'prompt_id');
    }

    /** @return HasMany<AiEvalCase, $this> */
    public function cases(): HasMany
    {
        return $this->hasMany(AiEvalCase::class, 'eval_suite_id');
    }

    /** @return HasMany<AiEvalRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AiEvalRun::class, 'eval_suite_id')->orderBy('created_at', 'desc');
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
