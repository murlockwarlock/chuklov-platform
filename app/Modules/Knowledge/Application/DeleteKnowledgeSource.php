<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DeleteKnowledgeSource
{
    public function __construct(
        private readonly KnowledgeAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, KnowledgeSource $source): void
    {
        $organization = $this->authorization->organizationForSource($actor, $source, OrganizationPermission::ManageKnowledge);

        DB::transaction(function () use ($actor, $source, $organization): void {
            $lockedSource = KnowledgeSource::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($source->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $revisions = DB::table('knowledge_revisions')
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->get(['id', 'storage_disk', 'storage_path']);
            $fileReferences = $revisions
                ->filter(fn (object $revision): bool => is_string($revision->storage_disk) && is_string($revision->storage_path))
                ->map(fn (object $revision): array => [(string) $revision->storage_disk, (string) $revision->storage_path])
                ->values()
                ->all();
            $retainedRevisionIds = DB::table('ai_run_rag_references')
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->distinct()
                ->pluck('knowledge_revision_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
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

            if ($retainedRevisionIds === []) {
                $lockedSource->delete();
            } else {
                $lockedSource->forceFill(['status' => 'retired', 'retired_at' => now()])->save();
            }

            DB::afterCommit(function () use ($fileReferences): void {
                foreach ($fileReferences as [$disk, $path]) {
                    Storage::disk($disk)->delete($path);
                }
            });
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
        });
    }
}
