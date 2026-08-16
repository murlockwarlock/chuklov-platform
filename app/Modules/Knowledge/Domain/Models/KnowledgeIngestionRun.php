<?php

namespace App\Modules\Knowledge\Domain\Models;

use App\Modules\Knowledge\Domain\Enums\IngestionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $knowledge_source_id
 * @property int $knowledge_revision_id
 * @property IngestionStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $processing_started_at
 * @property-read KnowledgeRevision $revision
 */
class KnowledgeIngestionRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => IngestionStatus::class,
            'processing_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<KnowledgeRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(KnowledgeRevision::class, 'knowledge_revision_id');
    }

    /** @return HasMany<KnowledgeChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
}
