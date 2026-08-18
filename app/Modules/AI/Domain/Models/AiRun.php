<?php

namespace App\Modules\AI\Domain\Models;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\ValueObjects\AiTokenUsage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $organization_id
 * @property AiCapability $capability
 * @property string $workflow_key
 * @property AiRunOrigin $origin
 * @property int|null $initiated_by_user_id
 * @property int|null $client_id
 * @property AiRunStatus $status
 * @property AiExecutionMode $execution_mode
 * @property int|null $prompt_id
 * @property int|null $prompt_version_id
 * @property int|null $model_config_id
 * @property int|null $model_release_id
 * @property string|null $requested_provider
 * @property string|null $requested_model
 * @property string|null $actual_provider
 * @property string|null $actual_model
 * @property array<string, mixed> $input_references
 * @property array<string, mixed>|null $execution_candidate_snapshot
 * @property array<string, mixed>|null $execution_policy_snapshot
 * @property string|null $rendered_prompt_digest
 * @property array<string, mixed> $context_provenance
 * @property string|null $structured_output_schema_version
 * @property bool $structured_output_valid
 * @property array<string, mixed> $token_usage
 * @property int|null $provider_cost_minor_units
 * @property int|null $settled_estimated_cost_minor_units
 * @property int $retrieval_embedding_reserved_cost_minor_units
 * @property CarbonInterface|null $retrieval_embedding_usage_date
 * @property string $retrieval_embedding_budget_status
 * @property int|null $retrieval_embedding_settled_cost_minor_units
 * @property array<string, mixed>|null $retrieval_embedding_pricing_snapshot
 * @property string $cost_currency
 * @property int $latency_ms
 * @property int $attempt_count
 * @property AiErrorCategory|null $error_category
 * @property string|null $error_message_sanitized
 * @property HumanReviewStatus $human_review_status
 * @property string|null $idempotency_key
 * @property string|null $worker_lease_token
 * @property CarbonInterface|null $worker_lease_expires_at
 * @property CarbonInterface|null $execution_deadline_at
 * @property CarbonInterface|null $queued_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property-read Organization $organization
 * @property-read User|null $initiatedBy
 * @property-read Client|null $client
 * @property-read AiPrompt|null $prompt
 * @property-read AiPromptVersion|null $promptVersion
 * @property-read AiModelRelease|null $modelRelease
 * @property-read AiRunPayload|null $payload
 * @property-read HasMany<AiRunAttempt, $this> $attempts
 * @property-read HasMany<AiRunToolCall, $this> $toolCalls
 * @property-read HasMany<AiRunRagReference, $this> $ragReferences
 * @property-read HasMany<AiRunHumanReview, $this> $humanReviews
 */
#[Fillable([
    'organization_id',
    'capability',
    'workflow_key',
    'origin',
    'initiated_by_user_id',
    'client_id',
    'status',
    'execution_mode',
    'prompt_id',
    'prompt_version_id',
    'model_config_id',
    'model_release_id',
    'requested_provider',
    'requested_model',
    'actual_provider',
    'actual_model',
    'input_references',
    'execution_candidate_snapshot',
    'execution_policy_snapshot',
    'rendered_prompt_digest',
    'context_provenance',
    'structured_output_schema_version',
    'structured_output_valid',
    'token_usage',
    'provider_cost_minor_units',
    'settled_estimated_cost_minor_units',
    'retrieval_embedding_reserved_cost_minor_units',
    'retrieval_embedding_usage_date',
    'retrieval_embedding_budget_status',
    'retrieval_embedding_settled_cost_minor_units',
    'retrieval_embedding_pricing_snapshot',
    'cost_currency',
    'latency_ms',
    'attempt_count',
    'error_category',
    'error_message_sanitized',
    'human_review_status',
    'idempotency_key',
    'worker_lease_token',
    'worker_lease_expires_at',
    'execution_deadline_at',
    'queued_at',
    'started_at',
    'finished_at',
])]
class AiRun extends Model
{
    protected $attributes = [
        'origin' => 'user',
        'status' => 'queued',
        'execution_mode' => 'sync',
        'input_references' => '[]',
        'execution_candidate_snapshot' => '[]',
        'execution_policy_snapshot' => '[]',
        'context_provenance' => '[]',
        'token_usage' => '[]',
        'retrieval_embedding_budget_status' => 'none',
        'retrieval_embedding_reserved_cost_minor_units' => 0,
        'structured_output_valid' => true,
        'cost_currency' => 'USD',
        'latency_ms' => 0,
        'attempt_count' => 1,
        'human_review_status' => 'not_required',
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** @return BelongsTo<AiPrompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiPrompt::class, 'prompt_id');
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

    /** @return HasOne<AiRunPayload, $this> */
    public function payload(): HasOne
    {
        return $this->hasOne(AiRunPayload::class, 'ai_run_id');
    }

    /** @return HasMany<AiRunAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(AiRunAttempt::class, 'ai_run_id')->orderBy('attempt_number', 'asc');
    }

    /** @return HasMany<AiRunToolCall, $this> */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AiRunToolCall::class, 'ai_run_id')->orderBy('call_index', 'asc');
    }

    /** @return HasMany<AiRunRagReference, $this> */
    public function ragReferences(): HasMany
    {
        return $this->hasMany(AiRunRagReference::class, 'ai_run_id')->orderBy('reference_index', 'asc');
    }

    /** @return HasMany<AiRunHumanReview, $this> */
    public function humanReviews(): HasMany
    {
        return $this->hasMany(AiRunHumanReview::class, 'ai_run_id')->orderBy('review_step', 'asc');
    }

    public function getTokenUsage(): AiTokenUsage
    {
        return AiTokenUsage::fromArray($this->token_usage ?? []);
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
            'origin' => AiRunOrigin::class,
            'status' => AiRunStatus::class,
            'execution_mode' => AiExecutionMode::class,
            'error_category' => AiErrorCategory::class,
            'human_review_status' => HumanReviewStatus::class,
            'input_references' => 'array',
            'execution_candidate_snapshot' => 'array',
            'execution_policy_snapshot' => 'array',
            'context_provenance' => 'array',
            'token_usage' => 'array',
            'retrieval_embedding_reserved_cost_minor_units' => 'integer',
            'retrieval_embedding_usage_date' => 'date',
            'retrieval_embedding_settled_cost_minor_units' => 'integer',
            'retrieval_embedding_pricing_snapshot' => 'array',
            'structured_output_valid' => 'boolean',
            'worker_lease_expires_at' => 'datetime',
            'execution_deadline_at' => 'datetime',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
