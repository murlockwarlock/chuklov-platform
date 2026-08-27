<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Knowledge\Domain\Enums\IngestionStatus;
use App\Modules\Knowledge\Domain\Models\KnowledgeIngestionRun;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Support\Facades\DB;

final class KnowledgeIngestionAnalytics
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, DashboardPeriod $period): int
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewKnowledge);

        return DB::table((new KnowledgeIngestionRun)->getTable())
            ->where('organization_id', (int) $organization->getKey())
            ->where('status', IngestionStatus::Failed->value)
            ->where('completed_at', '>=', $period->startUtc)
            ->where('completed_at', '<', $period->endUtc)
            ->count();
    }
}
