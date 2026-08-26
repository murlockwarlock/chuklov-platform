<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Support\Facades\DB;

final class AiFailureAnalytics
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, DashboardPeriod $period): int
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);

        return DB::table((new AiRun)->getTable())
            ->where('organization_id', (int) $organization->getKey())
            ->whereIn('status', [
                AiRunStatus::Failed->value,
                AiRunStatus::TimedOut->value,
                AiRunStatus::InvalidOutput->value,
            ])
            ->where('finished_at', '>=', $period->startUtc)
            ->where('finished_at', '<', $period->endUtc)
            ->count();
    }
}
