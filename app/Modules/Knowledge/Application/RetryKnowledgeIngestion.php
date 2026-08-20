<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Domain\Enums\KnowledgeRevisionStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Jobs\IngestKnowledgeRevision;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class RetryKnowledgeIngestion
{
    public function __construct(
        private readonly KnowledgeAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, KnowledgeSource $source, int $revisionId): KnowledgeRevision
    {
        $organization = $this->authorization->organizationForSource($actor, $source, OrganizationPermission::ManageKnowledge);
        $revision = DB::transaction(function () use ($actor, $source, $organization, $revisionId): KnowledgeRevision {
            $lockedSource = KnowledgeSource::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($source->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedSource->status !== KnowledgeSourceStatus::Active) {
                throw ValidationException::withMessages(['source' => 'Источник выключен или недоступен для повторной обработки.']);
            }

            $lockedRevision = KnowledgeRevision::query()
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->whereKey($revisionId)
                ->lockForUpdate()
                ->first();
            if (! $lockedRevision instanceof KnowledgeRevision) {
                throw ValidationException::withMessages(['revision' => 'Версия материала недоступна.']);
            }

            $latestRevision = KnowledgeRevision::query()
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();
            if (! $latestRevision instanceof KnowledgeRevision || $latestRevision->getKey() !== $lockedRevision->getKey()) {
                throw ValidationException::withMessages(['revision' => 'Повторно обработать можно только последнюю версию.']);
            }
            if ($lockedRevision->status !== KnowledgeRevisionStatus::Failed) {
                throw ValidationException::withMessages(['revision' => 'Повторная обработка доступна после ошибки.']);
            }

            $run = KnowledgeIngestionRun::query()
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->where('knowledge_revision_id', $lockedRevision->getKey())
                ->orderByDesc('id')
                ->first();
            $lockedRevision->update(['status' => KnowledgeRevisionStatus::Pending]);
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'knowledge.ingestion.retry_requested',
                targetType: KnowledgeRevision::class,
                targetId: (string) $lockedRevision->getKey(),
                metadata: [
                    'source_id' => $lockedSource->getKey(),
                    'revision_id' => $lockedRevision->getKey(),
                    'ingestion_run_id' => $run?->getKey(),
                    'attempt_number' => $run?->attempts,
                ],
            );

            return $lockedRevision->refresh();
        });

        try {
            $dispatch = IngestKnowledgeRevision::dispatch($organization->getKey(), $source->getKey(), $revision->getKey());
            unset($dispatch);
        } catch (Throwable) {
            try {
                $this->restoreFailedRevisionAfterDispatchFailure($actor, $organization->getKey(), $source->getKey(), $revision->getKey());
            } catch (Throwable) {
            }

            throw ValidationException::withMessages(['revision' => 'Не удалось запустить повторную обработку. Попробуйте ещё раз.']);
        }

        return $revision;
    }

    private function restoreFailedRevisionAfterDispatchFailure(User $actor, int $organizationId, int $sourceId, int $revisionId): void
    {
        DB::transaction(function () use ($actor, $organizationId, $sourceId, $revisionId): void {
            $lockedSource = KnowledgeSource::query()
                ->where('organization_id', $organizationId)
                ->whereKey($sourceId)
                ->lockForUpdate()
                ->first();
            if (! $lockedSource instanceof KnowledgeSource || $lockedSource->status !== KnowledgeSourceStatus::Active) {
                return;
            }

            $lockedRevision = KnowledgeRevision::query()
                ->where('organization_id', $organizationId)
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->whereKey($revisionId)
                ->lockForUpdate()
                ->first();
            if (! $lockedRevision instanceof KnowledgeRevision || $lockedRevision->status !== KnowledgeRevisionStatus::Pending) {
                return;
            }

            $latestRevisionId = KnowledgeRevision::query()
                ->where('organization_id', $organizationId)
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->orderByDesc('version')
                ->value('id');
            if ((int) $latestRevisionId !== (int) $lockedRevision->getKey()) {
                return;
            }

            $lockedRevision->update(['status' => KnowledgeRevisionStatus::Failed]);
            $this->audit->handle(
                organization: $lockedSource->organization,
                actor: $actor,
                action: 'knowledge.ingestion.retry_dispatch_failed',
                targetType: KnowledgeRevision::class,
                targetId: (string) $lockedRevision->getKey(),
                metadata: [
                    'source_id' => $lockedSource->getKey(),
                    'revision_id' => $lockedRevision->getKey(),
                    'restored' => true,
                ],
            );
        });
    }
}
