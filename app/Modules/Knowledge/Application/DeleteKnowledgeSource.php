<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Domain\Enums\KnowledgeStorageCleanupStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\Models\KnowledgeStorageCleanupOperation;
use App\Modules\Knowledge\Jobs\ProcessKnowledgeStorageCleanup;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DeleteKnowledgeSource
{
    public function __construct(
        private readonly KnowledgeAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, KnowledgeSource $source): void
    {
        $organization = $this->authorization->organizationForSource($actor, $source, OrganizationPermission::ManageKnowledge);

        $cleanupOperationIds = DB::transaction(function () use ($actor, $source, $organization): array {
            $lockedSource = KnowledgeSource::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($source->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $revisions = DB::table('knowledge_revisions')
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->get(['id', 'storage_disk', 'storage_path']);
            $retainedRevisionIds = DB::table('ai_run_rag_references')
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->distinct()
                ->pluck('knowledge_revision_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $retainedRevisionLookup = array_fill_keys($retainedRevisionIds, true);
            $cleanupCandidates = [];

            foreach ($revisions as $revision) {
                if (isset($retainedRevisionLookup[(int) $revision->id])
                    || ! is_string($revision->storage_disk)
                    || ! is_string($revision->storage_path)
                    || $revision->storage_disk === ''
                    || $revision->storage_path === '') {
                    continue;
                }

                $candidateKey = $revision->storage_disk."\0".$revision->storage_path;
                $cleanupCandidates[$candidateKey]['storage_disk'] = $revision->storage_disk;
                $cleanupCandidates[$candidateKey]['storage_path'] = $revision->storage_path;
                $cleanupCandidates[$candidateKey]['revision_ids'][] = (int) $revision->id;
            }

            $referencedChunkIds = DB::table('ai_run_rag_references')
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->distinct()
                ->pluck('knowledge_chunk_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $chunkQuery = DB::table('knowledge_chunks')
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey());
            $chunkCount = (clone $chunkQuery)->when($referencedChunkIds !== [], fn ($query) => $query->whereNotIn('id', $referencedChunkIds))->count();
            (clone $chunkQuery)->when($referencedChunkIds !== [], fn ($query) => $query->whereNotIn('id', $referencedChunkIds))->delete();

            $retainedRunIds = DB::table('knowledge_chunks')
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->distinct()
                ->pluck('knowledge_ingestion_run_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $runQuery = DB::table('knowledge_ingestion_runs')
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey());
            $runCount = (clone $runQuery)->when($retainedRunIds !== [], fn ($query) => $query->whereNotIn('id', $retainedRunIds))->count();
            $deletableRunQuery = (clone $runQuery)->when($retainedRunIds !== [], fn ($query) => $query->whereNotIn('id', $retainedRunIds));
            $deletableRunIds = (clone $deletableRunQuery)->pluck('id')->all();
            if ($deletableRunIds !== []) {
                DB::table('knowledge_ingestion_attempts')->where('organization_id', $organization->getKey())->whereIn('knowledge_ingestion_run_id', $deletableRunIds)->delete();
            }
            $deletableRunQuery->delete();

            $lockedSource->forceFill(['active_revision_id' => null])->save();
            $revisionQuery = DB::table('knowledge_revisions')
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey());
            $revisionCount = (clone $revisionQuery)->when($retainedRevisionIds !== [], fn ($query) => $query->whereNotIn('id', $retainedRevisionIds))->count();
            (clone $revisionQuery)->when($retainedRevisionIds !== [], fn ($query) => $query->whereNotIn('id', $retainedRevisionIds))->delete();

            $cleanupOperationIds = [];
            foreach ($cleanupCandidates as $candidate) {
                sort($candidate['revision_ids']);
                $cleanupKey = hash('sha256', implode("\0", [
                    (string) $organization->getKey(),
                    (string) $lockedSource->getKey(),
                    implode(',', $candidate['revision_ids']),
                    $candidate['storage_disk'],
                    $candidate['storage_path'],
                ]));
                $operation = KnowledgeStorageCleanupOperation::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('cleanup_key', $cleanupKey)
                    ->first();

                if ($operation === null) {
                    $operation = new KnowledgeStorageCleanupOperation;
                    $operation->forceFill([
                        'organization_id' => $organization->getKey(),
                        'cleanup_key' => $cleanupKey,
                        'storage_disk' => $candidate['storage_disk'],
                        'storage_path' => $candidate['storage_path'],
                        'status' => KnowledgeStorageCleanupStatus::Pending,
                        'attempts' => 0,
                        'available_at' => now(),
                    ])->save();
                }

                $cleanupOperationIds[] = (int) $operation->getKey();
            }

            if ($retainedRevisionIds === []) {
                $lockedSource->delete();
            } else {
                $lockedSource->forceFill(['status' => 'retired', 'retired_at' => now()])->save();
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'knowledge.source.deleted',
                targetType: KnowledgeSource::class,
                targetId: (string) $lockedSource->getKey(),
                metadata: [
                    'deleted_chunk_count' => (int) $chunkCount,
                    'deleted_run_count' => (int) $runCount,
                    'deleted_revision_count' => (int) $revisionCount,
                    'retained_revision_count' => count($retainedRevisionIds),
                ],
            );

            return $cleanupOperationIds;
        });

        foreach ($cleanupOperationIds as $operationId) {
            try {
                ProcessKnowledgeStorageCleanup::dispatch((int) $organization->getKey(), $operationId)
                    ->onQueue((string) config('rag.cleanup.queue', 'default'));
            } catch (Throwable $exception) {
                Log::warning('Knowledge storage cleanup dispatch failed.', [
                    'organization_id' => $organization->getKey(),
                    'operation_id' => $operationId,
                    'exception_class' => $exception::class,
                ]);
            }
        }
    }
}
