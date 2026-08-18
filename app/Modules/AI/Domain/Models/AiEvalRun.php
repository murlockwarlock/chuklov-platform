<?php

namespace App\Modules\AI\Domain\Models;

use App\Models\User;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $eval_suite_id
 * @property int $prompt_version_id
 * @property int $model_release_id
 * @property string|null $provider
 * @property string|null $model
 * @property int $total_cases
 * @property int $passed_cases
 * @property int $failed_cases
 * @property array<string, mixed> $results_payload
 * @property int|null $executed_by_user_id
 * @property-read Organization $organization
 * @property-read AiEvalSuite $suite
 * @property-read AiPromptVersion $promptVersion
 * @property-read User|null $executedBy
 */
#[Fillable([
    'organization_id',
    'eval_suite_id',
    'prompt_version_id',
    'model_release_id',
    'provider',
    'model',
    'total_cases',
    'passed_cases',
    'failed_cases',
    'results_payload',
    'executed_by_user_id',
])]
class AiEvalRun extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiEvalSuite, $this> */
    public function suite(): BelongsTo
    {
        return $this->belongsTo(AiEvalSuite::class, 'eval_suite_id');
    }

    /** @return BelongsTo<AiPromptVersion, $this> */
    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(AiPromptVersion::class, 'prompt_version_id');
    }

    /** @return BelongsTo<AiModelRelease, $this> */
    public function modelRelease(): BelongsTo
    {
        return $this->belongsTo(AiModelRelease::class, 'model_release_id');
    }

    /** @return BelongsTo<User, $this> */
    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
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
            'total_cases' => 'integer',
            'passed_cases' => 'integer',
            'failed_cases' => 'integer',
            'results_payload' => 'array',
        ];
    }
}
