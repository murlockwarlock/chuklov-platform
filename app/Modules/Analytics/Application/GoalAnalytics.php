<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\GoalAnalyticsData;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Tracker\Domain\Models\TrackerGoalTag;
use Illuminate\Support\Facades\DB;

final readonly class GoalAnalytics
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(
        User $actor,
        DashboardPeriod $period,
        ?Client $client = null,
        ?int $goalTagId = null,
    ): GoalAnalyticsData {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $organizationId = (int) $organization->getKey();
        if ($client instanceof Client && (int) $client->organization_id !== $organizationId) {
            abort(404);
        }
        if ($goalTagId !== null && ! TrackerGoalTag::query()
            ->where('organization_id', $organizationId)
            ->whereKey($goalTagId)
            ->exists()) {
            abort(404);
        }
        $clientId = $client?->getKey();

        $goals = DB::table('tracker_task_goal_tags as links')
            ->join('tracker_tasks as tasks', function ($join): void {
                $join->on('tasks.organization_id', '=', 'links.organization_id')
                    ->on('tasks.id', '=', 'links.tracker_task_id');
            })
            ->join('tracker_goal_tags as tags', function ($join): void {
                $join->on('tags.organization_id', '=', 'links.organization_id')
                    ->on('tags.id', '=', 'links.tracker_goal_tag_id');
            })
            ->where('links.organization_id', $organizationId)
            ->where('tasks.created_at', '>=', $period->startUtc)
            ->where('tasks.created_at', '<', $period->endUtc)
            ->when($clientId !== null, fn ($query) => $query->where('tasks.client_id', $clientId))
            ->when($goalTagId !== null, fn ($query) => $query->where('links.tracker_goal_tag_id', $goalTagId))
            ->select('tags.name', 'tags.group_name')
            ->selectRaw('COUNT(DISTINCT tasks.client_id) as client_count')
            ->selectRaw('COUNT(DISTINCT tasks.id) as task_count')
            ->groupBy('tags.name', 'tags.group_name')
            ->orderByDesc('client_count')
            ->orderBy('tags.name')
            ->get()
            ->map(static fn (object $row): array => [
                'name' => (string) $row->name,
                'group' => $row->group_name !== null ? (string) $row->group_name : null,
                'client_count' => (int) $row->client_count,
                'task_count' => (int) $row->task_count,
            ])
            ->values()
            ->all();

        return new GoalAnalyticsData($goals);
    }
}
