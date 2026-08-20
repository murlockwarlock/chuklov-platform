<?php

namespace App\Modules\Knowledge\Domain\Models;

use App\Modules\Knowledge\Domain\Enums\KnowledgeIngestionAttemptStatus;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $knowledge_source_id
 * @property int $knowledge_revision_id
 * @property int $knowledge_ingestion_run_id
 * @property int $attempt_number
 * @property KnowledgeIngestionAttemptStatus $status
 * @property string|null $error_code
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 */
class KnowledgeIngestionAttempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeIngestionAttemptStatus::class,
            'attempt_number' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
    public function run(): BelongsTo
    {
        return $this->belongsTo(KnowledgeIngestionRun::class, 'knowledge_ingestion_run_id');
    }

    protected static function booted(): void
    {
        static::updating(function (KnowledgeIngestionAttempt $attempt): void {
            if ($attempt->getRawOriginal('status') !== KnowledgeIngestionAttemptStatus::Processing->value && $attempt->isDirty()) {
                throw new LogicException('Terminal ingestion attempts are immutable.');
            }
        });

        static::deleting(function (KnowledgeIngestionAttempt $attempt): void {
            if ($attempt->status->isTerminal()) {
                throw new LogicException('Terminal ingestion attempts are immutable.');
            }
        });
    }
}
