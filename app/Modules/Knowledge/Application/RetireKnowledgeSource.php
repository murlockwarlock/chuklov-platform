<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
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
            $source->update(['status' => 'retired', 'retired_at' => now()]);
            $source->revisions()->whereNotIn('status', ['failed', 'retired'])->update(['status' => 'retired', 'retired_at' => now()]);
            $this->audit->handle($organization, $actor, 'knowledge.source.retired', KnowledgeSource::class, (string) $source->getKey(), ['active_revision_id' => $source->active_revision_id]);

            return $source->refresh();
        });
    }
}
