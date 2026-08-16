<?php

namespace App\Modules\Knowledge\Application;

use App\Modules\Knowledge\Domain\Contracts\EmbeddingGenerator;
use App\Modules\Knowledge\Domain\Enums\KnowledgeRevisionStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeChunk;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\ValueObjects\ChunkData;
use App\Modules\Knowledge\Domain\ValueObjects\ChunkingConfiguration;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ProcessKnowledgeIngestion
{
    public function __construct(
        private readonly ClaimKnowledgeIngestionRun $claim,
        private readonly DeterministicTextChunker $chunker,
        private readonly EmbeddingGenerator $embeddings,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(int $organizationId, int $sourceId, int $revisionId): void
    {
        $embeddingConfiguration = EmbeddingConfiguration::active();
        $chunkingConfiguration = ChunkingConfiguration::active();
        $run = $this->claim->handle($organizationId, $sourceId, $revisionId, $embeddingConfiguration, $chunkingConfiguration);

        if (! $run instanceof KnowledgeIngestionRun) {
            return;
        }
        $claimedAttempt = $run->attempts;

        try {
            $revision = KnowledgeRevision::query()->where('organization_id', $organizationId)->whereKey($revisionId)->firstOrFail();
            $text = $revision->content;
            if ($text === null && $revision->storage_disk !== null && $revision->storage_path !== null) {
                $text = Storage::disk($revision->storage_disk)->get($revision->storage_path);
            }
            if (! is_string($text) || trim($text) === '' || hash('sha256', $text) !== $revision->content_checksum) {
                throw new RuntimeException('invalid_source_content');
            }
            $normalized = $this->chunker->normalize($text);
            if (mb_strlen($normalized) > (int) config('rag.uploads.maximum_extracted_characters')) {
                throw new RuntimeException('source_text_too_large');
            }
            $chunks = $this->chunker->chunk($normalized, $chunkingConfiguration, $revision->source_reference);
            if ($chunks === []) {
                throw new RuntimeException('empty_source_content');
            }

            foreach (array_chunk($chunks, 50) as $chunkBatch) {
                $vectors = $this->embeddings->generate(array_map(static fn ($chunk): string => $chunk->content, $chunkBatch), $embeddingConfiguration);
                if (! $this->persistChunkBatch($organizationId, $sourceId, $revisionId, $run->getKey(), $claimedAttempt, $chunkBatch, $vectors)) {
                    return;
                }
            }

            DB::transaction(function () use ($organizationId, $sourceId, $revisionId, $run, $claimedAttempt): void {
                $source = KnowledgeSource::query()->where('organization_id', $organizationId)->whereKey($sourceId)->lockForUpdate()->firstOrFail();
                $revision = KnowledgeRevision::query()->where('organization_id', $organizationId)->whereKey($revisionId)->lockForUpdate()->firstOrFail();
                $lockedRun = KnowledgeIngestionRun::query()->where('organization_id', $organizationId)->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
                if ($lockedRun->attempts !== $claimedAttempt || $lockedRun->status->value !== 'processing') {
                    return;
                }
                $lockedRun->update(['status' => 'ready', 'completed_at' => now()]);

                if ($source->status->value === 'retired' || $revision->status === KnowledgeRevisionStatus::Retired) {
                    return;
                }

                $currentVersion = $source->activeRevision()->value('version');
                if ($source->active_revision_id === $revision->getKey()) {
                    $revision->update(['status' => 'ready', 'ready_at' => $revision->ready_at ?? now()]);
                } elseif ($currentVersion === null || (int) $currentVersion < $revision->version) {
                    if ($source->active_revision_id !== null) {
                        KnowledgeRevision::query()->where('organization_id', $organizationId)->whereKey($source->active_revision_id)->update(['status' => 'stale']);
                    }
                    $revision->update(['status' => 'ready', 'ready_at' => now()]);
                    $source->update(['active_revision_id' => $revision->getKey()]);
                } else {
                    $revision->update(['status' => 'stale', 'ready_at' => now()]);
                }

                $organization = Organization::query()->findOrFail($organizationId);
                $this->audit->handle($organization, null, 'knowledge.ingestion.completed', KnowledgeRevision::class, (string) $revisionId, [
                    'source_id' => $sourceId,
                    'revision_id' => $revisionId,
                    'chunk_count' => $lockedRun->chunks()->count(),
                ]);
            });
        } catch (Throwable $exception) {
            $errorCode = $this->errorCode($exception);
            $failedCurrentAttempt = DB::transaction(function () use ($organizationId, $sourceId, $revisionId, $run, $claimedAttempt, $errorCode): bool {
                $updated = KnowledgeIngestionRun::query()
                    ->where('organization_id', $organizationId)
                    ->whereKey($run->getKey())
                    ->where('attempts', $claimedAttempt)
                    ->where('status', 'processing')
                    ->update(['status' => 'failed', 'error_code' => $errorCode, 'completed_at' => now()]);
                if ($updated === 0) {
                    return false;
                }
                KnowledgeRevision::query()->where('organization_id', $organizationId)->whereKey($revisionId)->where('status', 'processing')->update(['status' => 'failed']);
                $organization = Organization::query()->findOrFail($organizationId);
                $this->audit->handle($organization, null, 'knowledge.ingestion.failed', KnowledgeRevision::class, (string) $revisionId, ['source_id' => $sourceId, 'revision_id' => $revisionId, 'error_code' => $errorCode]);

                return true;
            });

            if (! $failedCurrentAttempt) {
                return;
            }

            throw new RuntimeException('Knowledge ingestion failed.');
        }
    }

    private function errorCode(Throwable $exception): string
    {
        $message = $exception->getMessage();

        return in_array($message, ['invalid_source_content', 'source_text_too_large', 'empty_source_content'], true)
            ? $message
            : 'embedding_or_persistence_failed';
    }

    /**
     * @param  list<ChunkData>  $chunks
     * @param  list<list<float>>  $vectors
     */
    private function persistChunkBatch(
        int $organizationId,
        int $sourceId,
        int $revisionId,
        int $runId,
        int $claimedAttempt,
        array $chunks,
        array $vectors,
    ): bool {
        return DB::transaction(function () use ($organizationId, $sourceId, $revisionId, $runId, $claimedAttempt, $chunks, $vectors): bool {
            KnowledgeRevision::query()
                ->where('organization_id', $organizationId)
                ->where('knowledge_source_id', $sourceId)
                ->whereKey($revisionId)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedRun = KnowledgeIngestionRun::query()
                ->where('organization_id', $organizationId)
                ->where('knowledge_source_id', $sourceId)
                ->where('knowledge_revision_id', $revisionId)
                ->whereKey($runId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRun->attempts !== $claimedAttempt || $lockedRun->status->value !== 'processing') {
                return false;
            }

            foreach ($chunks as $index => $chunk) {
                KnowledgeChunk::query()->updateOrCreate(
                    ['organization_id' => $organizationId, 'knowledge_ingestion_run_id' => $runId, 'chunk_index' => $chunk->index],
                    [
                        'knowledge_source_id' => $sourceId,
                        'knowledge_revision_id' => $revisionId,
                        'start_offset' => $chunk->startOffset,
                        'end_offset' => $chunk->endOffset,
                        'source_reference' => $chunk->sourceReference,
                        'content_checksum' => hash('sha256', $chunk->content),
                        'content' => $chunk->content,
                        'embedding' => $vectors[$index],
                    ],
                );
            }

            return true;
        });
    }
}
