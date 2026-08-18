<?php

namespace App\Modules\AI\Domain\Models;

use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $ai_run_id
 * @property int $reference_index
 * @property int $knowledge_source_id
 * @property int $knowledge_revision_id
 * @property int $knowledge_chunk_id
 * @property int|null $ai_run_tool_call_id
 * @property string $retrieval_type
 * @property int $chunk_index
 * @property float $similarity_score
 * @property string $configuration_key
 * @property-read Organization $organization
 * @property-read AiRun $run
 */
#[Fillable([
    'organization_id',
    'ai_run_id',
    'reference_index',
    'knowledge_source_id',
    'knowledge_revision_id',
    'knowledge_chunk_id',
    'ai_run_tool_call_id',
    'retrieval_type',
    'chunk_index',
    'similarity_score',
    'configuration_key',
])]
class AiRunRagReference extends Model
{
    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AiRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    /** @return BelongsTo<AiRunToolCall, $this> */
    public function toolCall(): BelongsTo
    {
        return $this->belongsTo(AiRunToolCall::class, 'ai_run_tool_call_id');
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
            'reference_index' => 'integer',
            'knowledge_source_id' => 'integer',
            'knowledge_revision_id' => 'integer',
            'knowledge_chunk_id' => 'integer',
            'ai_run_tool_call_id' => 'integer',
            'chunk_index' => 'integer',
            'similarity_score' => 'float',
        ];
    }
}
