<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;

final class RetireKnowledgeSource
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
            $retiredAt = now();
            $revisions = KnowledgeRevision::query()
                ->where('organization_id', $organization->getKey())
                ->where('knowledge_source_id', $lockedSource->getKey())
                ->whereNotIn('status', ['failed', 'retired'])
                ->lockForUpdate()
                ->get();
            foreach ($revisions as $revision) {
                $revision->update(['status' => 'retired', 'retired_at' => $retiredAt]);
            }
            $lockedSource->update(['status' => 'retired', 'retired_at' => $retiredAt]);
            $this->audit->handle($organization, $actor, 'knowledge.source.retired', KnowledgeSource::class, (string) $lockedSource->getKey(), ['active_revision_id' => $lockedSource->active_revision_id]);

            return $lockedSource->refresh();
        });
    }
}
