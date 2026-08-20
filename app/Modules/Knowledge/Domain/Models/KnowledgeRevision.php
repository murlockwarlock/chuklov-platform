<?php

namespace App\Modules\Knowledge\Domain\Models;

use App\Modules\Knowledge\Domain\Enums\KnowledgeRevisionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $knowledge_source_id
 * @property int $version
 * @property KnowledgeRevisionStatus $status
 * @property string|null $content
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property string|null $source_reference
 * @property string $content_checksum
 * @property CarbonImmutable|null $ready_at
 * @property-read KnowledgeSource $source
 */
class KnowledgeRevision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeRevisionStatus::class,
            'ready_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<KnowledgeSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }

    /** @return HasMany<KnowledgeIngestionRun, $this> */
    public function ingestionRuns(): HasMany
    {
        return $this->hasMany(KnowledgeIngestionRun::class);
    }

    /** @return HasOne<KnowledgeIngestionRun, $this> */
    public function latestIngestionRun(): HasOne
    {
        return $this->hasOne(KnowledgeIngestionRun::class)->latestOfMany();
    }

    /** @return HasMany<KnowledgeIngestionAttempt, $this> */
    public function ingestionAttemptHistory(): HasMany
    {
        return $this->hasMany(KnowledgeIngestionAttempt::class, 'knowledge_revision_id');
    }

    protected static function booted(): void
    {
        static::updating(function (KnowledgeRevision $revision): void {
            $immutable = ['organization_id', 'knowledge_source_id', 'version', 'content', 'storage_disk', 'storage_path', 'original_filename', 'mime_type', 'size_bytes', 'content_checksum', 'source_reference', 'created_by_user_id'];
            if ($revision->isDirty($immutable)) {
                throw new LogicException('Knowledge revisions are immutable.');
            }
        });
    }
}
