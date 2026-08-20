<?php

namespace App\Modules\Knowledge\Application;

use App\Modules\Knowledge\Domain\Enums\KnowledgeIngestionAttemptStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeRevisionStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionAttempt;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\ValueObjects\ChunkingConfiguration;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use Illuminate\Support\Facades\DB;

final class ClaimKnowledgeIngestionRun
{
    public function handle(
        int $organizationId,
        int $sourceId,
        int $revisionId,
        EmbeddingConfiguration $embeddingConfiguration,
        ChunkingConfiguration $chunkingConfiguration,
    ): ?KnowledgeIngestionRun {
        $configurationKey = hash('sha256', $embeddingConfiguration->key().'|'.$chunkingConfiguration->key());
        $processingStaleCutoff = now()->subSeconds((int) config('rag.processing_stale_after_seconds'));

        return DB::transaction(function () use ($organizationId, $sourceId, $revisionId, $embeddingConfiguration, $chunkingConfiguration, $configurationKey, $processingStaleCutoff): ?KnowledgeIngestionRun {
            $revision = KnowledgeRevision::query()
                ->where('organization_id', $organizationId)
                ->where('knowledge_source_id', $sourceId)
                ->whereKey($revisionId)
                ->lockForUpdate()
                ->firstOrFail();
            $run = KnowledgeIngestionRun::query()->firstOrCreate(
                ['organization_id' => $organizationId, 'knowledge_revision_id' => $revisionId, 'configuration_key' => $configurationKey],
                [
                    'knowledge_source_id' => $sourceId,
                    'status' => 'pending',
                    'chunk_strategy' => $chunkingConfiguration->strategy,
                    'chunk_version' => $chunkingConfiguration->version,
                    'chunk_target_characters' => $chunkingConfiguration->targetCharacters,
                    'chunk_maximum_characters' => $chunkingConfiguration->maximumCharacters,
                    'chunk_overlap_characters' => $chunkingConfiguration->overlapCharacters,
                    'embedding_provider' => $embeddingConfiguration->provider,
                    'embedding_model' => $embeddingConfiguration->model,
                    'embedding_dimensions' => $embeddingConfiguration->dimensions,
                    'embedding_configuration_version' => $embeddingConfiguration->version,
                ],
            );
            $run = KnowledgeIngestionRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            $processingExpired = $run->status->value === 'processing'
                && ($run->processing_started_at === null || $run->processing_started_at->lt($processingStaleCutoff));
            if ($run->status->value === 'ready' || ($run->status->value === 'processing' && ! $processingExpired) || $revision->status === KnowledgeRevisionStatus::Retired) {
                return null;
            }

            $startedAt = now();
            if ($processingExpired && $run->attempts > 0) {
                $staleAttempt = KnowledgeIngestionAttempt::query()
                    ->where('organization_id', $organizationId)
                    ->where('knowledge_source_id', $sourceId)
                    ->where('knowledge_revision_id', $revisionId)
                    ->where('knowledge_ingestion_run_id', $run->getKey())
                    ->where('attempt_number', $run->attempts)
                    ->where('status', KnowledgeIngestionAttemptStatus::Processing)
                    ->lockForUpdate()
                    ->first();
                if ($staleAttempt instanceof KnowledgeIngestionAttempt) {
                    $staleAttempt->update([
                        'status' => KnowledgeIngestionAttemptStatus::Abandoned,
                        'error_code' => 'stale_processing_reclaimed',
                        'completed_at' => $startedAt,
                    ]);
                }
            }

            $attemptNumber = $run->attempts + 1;
            $run->chunks()->delete();
            $run->update([
                'status' => 'processing',
                'attempts' => $attemptNumber,
                'error_code' => null,
                'processing_started_at' => $startedAt,
                'completed_at' => null,
            ]);
            if (! in_array($revision->status, [KnowledgeRevisionStatus::Ready, KnowledgeRevisionStatus::Stale], true)) {
                $revision->update(['status' => 'processing']);
            }
            KnowledgeIngestionAttempt::query()->create([
                'organization_id' => $organizationId,
                'knowledge_source_id' => $sourceId,
                'knowledge_revision_id' => $revisionId,
                'knowledge_ingestion_run_id' => $run->getKey(),
                'attempt_number' => $attemptNumber,
                'status' => KnowledgeIngestionAttemptStatus::Processing,
                'started_at' => $startedAt,
            ]);

            return $run->refresh();
        });
    }
}
