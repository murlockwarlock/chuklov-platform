<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Domain\Enums\IngestionStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeRevisionStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Knowledge\Jobs\IngestKnowledgeRevision;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ReprocessKnowledgeForSearch
{
    public function __construct(
        private readonly KnowledgeAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, KnowledgeSource $source, int $revisionId): void
    {
        $organization = $this->authorization->organizationForSource($actor, $source, OrganizationPermission::ManageKnowledge);
        $embeddingConfiguration = EmbeddingConfiguration::active();

        DB::transaction(function () use ($actor, $source, $organization, $revisionId, $embeddingConfiguration): void {
            $lockedSource = KnowledgeSource::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($source->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedSource->status !== KnowledgeSourceStatus::Active) {
                throw ValidationException::withMessages(['source' => 'Источник выключен или недоступен для подготовки к поиску.']);
            }

            $lockedRevision = KnowledgeRevision::query()
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->whereKey($revisionId)
                ->lockForUpdate()
                ->first();
            if (! $lockedRevision instanceof KnowledgeRevision) {
                throw ValidationException::withMessages(['revision' => 'Материал больше недоступен для подготовки к поиску.']);
            }
            if ((int) $lockedSource->active_revision_id !== (int) $lockedRevision->getKey()) {
                throw ValidationException::withMessages(['revision' => 'Подготовить для поиска можно только текущий материал.']);
            }
            if ($lockedRevision->status !== KnowledgeRevisionStatus::Ready) {
                throw ValidationException::withMessages(['revision' => 'Подготовка для поиска доступна только для готового материала.']);
            }

            $compatibleRuns = KnowledgeIngestionRun::query()
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->where('knowledge_revision_id', $lockedRevision->getKey())
                ->where('embedding_provider', $embeddingConfiguration->provider)
                ->where('embedding_model', $embeddingConfiguration->model)
                ->where('embedding_dimensions', $embeddingConfiguration->dimensions)
                ->where('embedding_configuration_version', $embeddingConfiguration->version);
            if ((clone $compatibleRuns)->where('status', IngestionStatus::Ready)->exists()) {
                throw ValidationException::withMessages(['revision' => 'Материал уже подготовлен для поиска.']);
            }
            if ((clone $compatibleRuns)->where('status', IngestionStatus::Processing)->exists()) {
                throw ValidationException::withMessages(['revision' => 'Подготовка материала для поиска уже выполняется.']);
            }

            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'knowledge.ingestion.reprocess_requested',
                targetType: KnowledgeRevision::class,
                targetId: (string) $lockedRevision->getKey(),
                metadata: [
                    'source_id' => $lockedSource->getKey(),
                    'revision_id' => $lockedRevision->getKey(),
                ],
            );
        });

        try {
            $dispatch = IngestKnowledgeRevision::dispatch($organization->getKey(), $source->getKey(), $revisionId);
            unset($dispatch);
        } catch (Throwable) {
            throw ValidationException::withMessages(['revision' => 'Не удалось запустить подготовку для поиска. Попробуйте ещё раз.']);
        }
    }
}
