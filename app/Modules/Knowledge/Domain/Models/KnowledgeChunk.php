<?php

namespace App\Modules\Knowledge\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $knowledge_source_id
 * @property int $knowledge_revision_id
 * @property int $knowledge_ingestion_run_id
 * @property int $chunk_index
 * @property int $start_offset
 * @property int $end_offset
 * @property string $content
 * @property string|null $source_reference
 * @property list<float> $embedding
 * @property int $chunk_id
 * @property int $source_id
 * @property string $source_title
 * @property string $source_type
 * @property int $revision_id
 * @property int $revision_version
 * @property int $ingestion_run_id
 * @property float $distance
 */
class KnowledgeChunk extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['embedding' => 'array'];
    }

    /** @return BelongsTo<KnowledgeSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }

    /** @return BelongsTo<KnowledgeRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(KnowledgeRevision::class, 'knowledge_revision_id');
    }

    /** @return BelongsTo<KnowledgeIngestionRun, $this> */
    public function ingestionRun(): BelongsTo
    {
        return $this->belongsTo(KnowledgeIngestionRun::class, 'knowledge_ingestion_run_id');
    }
}
