<?php

namespace App\Modules\Knowledge\Application;

use App\Modules\Knowledge\Domain\Enums\KnowledgeRevisionStatus;
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

        return DB::transaction(function () use ($organizationId, $sourceId, $revisionId, $embeddingConfiguration, $chunkingConfiguration, $configurationKey): ?KnowledgeIngestionRun {
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
                && $run->processing_started_at !== null
                && $run->processing_started_at->lt(now()->subSeconds((int) config('rag.processing_stale_after_seconds')));
            if ($run->status->value === 'ready' || ($run->status->value === 'processing' && ! $processingExpired) || $revision->status === KnowledgeRevisionStatus::Retired) {
                return null;
            }

            $run->chunks()->delete();
            $run->update([
                'status' => 'processing',
                'attempts' => $run->attempts + 1,
                'error_code' => null,
                'processing_started_at' => now(),
                'completed_at' => null,
            ]);
            if (! in_array($revision->status, [KnowledgeRevisionStatus::Ready, KnowledgeRevisionStatus::Stale], true)) {
                $revision->update(['status' => 'processing']);
            }

            return $run->refresh();
        });
    }
}
