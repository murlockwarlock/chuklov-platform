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
            'chunk_index' => 'integer',
            'similarity_score' => 'float',
        ];
    }
}
