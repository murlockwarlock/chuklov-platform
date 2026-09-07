<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Domain\Enums\KnowledgeExtractionStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RequestKnowledgeAiParsing
{
    public function __construct(
        private readonly KnowledgeAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, KnowledgeSource $source, int $revisionId): KnowledgeRevision
    {
        $organization = $this->authorization->organizationForSource($actor, $source, OrganizationPermission::ManageKnowledge);

        return DB::transaction(function () use ($actor, $source, $organization, $revisionId): KnowledgeRevision {
            $lockedSource = KnowledgeSource::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($source->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $revision = KnowledgeRevision::query()
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->whereKey($revisionId)
                ->lockForUpdate()
                ->first();

            if (! $revision instanceof KnowledgeRevision) {
                throw ValidationException::withMessages(['revision' => 'Версия материала недоступна.']);
            }

            if (strtolower(pathinfo((string) $revision->original_filename, PATHINFO_EXTENSION)) !== 'pdf') {
                throw ValidationException::withMessages(['revision' => 'AI-разбор доступен только для PDF без найденного текста.']);
            }

            if ($revision->extraction_status === KnowledgeExtractionStatus::AiParseRequested->value) {
                return $revision->refresh();
            }

            if ($revision->extraction_status !== KnowledgeExtractionStatus::TextNotFound->value) {
                throw ValidationException::withMessages(['revision' => 'AI-разбор доступен только после явного состояния «текст не найден».']);
            }

            $revision->update(['extraction_status' => KnowledgeExtractionStatus::AiParseRequested->value]);
            $this->audit->handle(
                organization: $organization,
                actor: $actor,
                action: 'knowledge.revision.ai_parse_requested',
                targetType: KnowledgeRevision::class,
                targetId: (string) $revision->getKey(),
                metadata: [
                    'source_id' => $lockedSource->getKey(),
                    'revision_id' => $revision->getKey(),
                    'capability' => 'not_configured',
                    'prompt_version' => 'not_configured',
                ],
            );

            return $revision->refresh();
        });
    }
}
