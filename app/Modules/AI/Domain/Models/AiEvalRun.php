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
 * @property float $pass_percentage
 * @property int $total_latency_ms
 * @property int $average_latency_ms
 * @property int $total_prompt_tokens
 * @property int $total_completion_tokens
 * @property int $retry_count
 * @property int $failover_count
 * @property int $execution_error_count
 * @property int $rag_failed_cases
 * @property int $human_reviewed_cases
 * @property int|null $estimated_cost_minor_units
 * @property int|null $provider_cost_minor_units
 * @property array<string, mixed> $results_payload
 * @property array<string, mixed>|null $metrics_payload
 * @property array<string, mixed>|null $provenance_snapshot
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
    'pass_percentage',
    'total_latency_ms',
    'average_latency_ms',
    'total_prompt_tokens',
    'total_completion_tokens',
    'retry_count',
    'failover_count',
    'execution_error_count',
    'rag_failed_cases',
    'human_reviewed_cases',
    'estimated_cost_minor_units',
    'provider_cost_minor_units',
    'results_payload',
    'metrics_payload',
    'provenance_snapshot',
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
            'pass_percentage' => 'float',
            'total_latency_ms' => 'integer',
            'average_latency_ms' => 'integer',
            'total_prompt_tokens' => 'integer',
            'total_completion_tokens' => 'integer',
            'retry_count' => 'integer',
            'failover_count' => 'integer',
            'execution_error_count' => 'integer',
            'rag_failed_cases' => 'integer',
            'human_reviewed_cases' => 'integer',
            'estimated_cost_minor_units' => 'integer',
            'provider_cost_minor_units' => 'integer',
            'results_payload' => 'array',
            'metrics_payload' => 'array',
            'provenance_snapshot' => 'array',
        ];
    }
}
