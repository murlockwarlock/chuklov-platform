<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
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
        $revision = $source->revisions()->whereNotNull('ready_at')->orderByDesc('version')->first();
        if (! $revision instanceof KnowledgeRevision) {
            throw ValidationException::withMessages(['source' => 'У источника нет готовой версии для восстановления.']);
        }

        return DB::transaction(function () use ($actor, $source, $revision, $organization): KnowledgeSource {
            $source->revisions()->whereKey($revision->getKey())->update(['status' => 'ready', 'retired_at' => null]);
            $source->update(['status' => 'active', 'active_revision_id' => $revision->getKey(), 'retired_at' => null]);
            $this->audit->handle($organization, $actor, 'knowledge.source.reactivated', KnowledgeSource::class, (string) $source->getKey(), ['active_revision_id' => $revision->getKey()]);

            return $source->refresh();
        });
    }
}
