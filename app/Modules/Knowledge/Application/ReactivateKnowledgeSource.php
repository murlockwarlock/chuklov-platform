<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Domain\Enums\KnowledgeRevisionStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReactivateKnowledgeSource
{
    public function __construct(
        private readonly KnowledgeAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(User $actor, KnowledgeSource $source): KnowledgeSource
    {
        $organization = $this->authorization->organizationForSource($actor, $source, OrganizationPermission::ManageKnowledge);

        return DB::transaction(function () use ($actor, $source, $organization): KnowledgeSource {
            $lockedSource = KnowledgeSource::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey($source->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedSource->status !== KnowledgeSourceStatus::Retired) {
                throw ValidationException::withMessages(['source' => 'Источник уже доступен.']);
            }

            $revision = KnowledgeRevision::query()
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->whereNotNull('ready_at')
                ->whereIn('status', [
                    KnowledgeRevisionStatus::Ready,
                    KnowledgeRevisionStatus::Stale,
                    KnowledgeRevisionStatus::Retired,
                ])
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();
            if (! $revision instanceof KnowledgeRevision) {
                throw ValidationException::withMessages(['source' => 'У источника нет готовой версии для восстановления.']);
            }

            $revision->update(['status' => KnowledgeRevisionStatus::Ready, 'retired_at' => null]);
            $lockedSource->update(['status' => KnowledgeSourceStatus::Active, 'active_revision_id' => $revision->getKey(), 'retired_at' => null]);
            $this->audit->handle($organization, $actor, 'knowledge.source.reactivated', KnowledgeSource::class, (string) $lockedSource->getKey(), ['active_revision_id' => $revision->getKey()]);

            return $lockedSource->refresh();
        });
    }
}
