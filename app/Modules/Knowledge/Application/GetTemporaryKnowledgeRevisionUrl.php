<?php

namespace App\Modules\Knowledge\Application;

use App\Models\User;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Knowledge\Domain\Exceptions\KnowledgeRevisionFileUnavailable;
use App\Modules\Knowledge\Domain\Models\KnowledgeRevision;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Support\Facades\URL;

final readonly class GetTemporaryKnowledgeRevisionUrl
{
    public function __construct(
        private KnowledgeAuthorization $authorization,
    ) {}

    public function handle(User $actor, KnowledgeSource $source, KnowledgeRevision $revision, int $ttlMinutes = 15): string
    {
        $this->authorization->organizationForRevision($actor, $source, $revision, OrganizationPermission::ViewKnowledge);
        if ($source->type !== KnowledgeSourceType::UploadedText || $revision->storage_disk === null || $revision->storage_path === null) {
            throw new KnowledgeRevisionFileUnavailable;
        }

        return URL::temporarySignedRoute(
            'admin.knowledge.revision.download',
            now()->addMinutes($ttlMinutes),
            [
                'knowledgeSourceId' => $source->getKey(),
                'knowledgeRevisionId' => $revision->getKey(),
            ],
        );
    }
}
