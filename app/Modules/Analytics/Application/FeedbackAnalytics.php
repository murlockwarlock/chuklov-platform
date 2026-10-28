<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\FeedbackAnalyticsData;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Support\Facades\DB;

final readonly class FeedbackAnalytics
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, DashboardPeriod $period, ?Client $client = null): FeedbackAnalyticsData
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $organizationId = (int) $organization->getKey();
        if ($client instanceof Client && (int) $client->organization_id !== $organizationId) {
            abort(404);
        }

        $base = DB::table('feedback_submissions')
            ->where('organization_id', $organizationId)
            ->where('submitted_at', '>=', $period->startUtc)
            ->where('submitted_at', '<', $period->endUtc)
            ->when($client instanceof Client, fn ($query) => $query->where('client_id', $client->getKey()));
        $summary = (clone $base)
            ->selectRaw('COUNT(*) as submission_count')
            ->selectRaw('AVG(score) as average_score')
            ->first();

        $themes = DB::table('feedback_submission_themes as links')
            ->join('feedback_submissions as submissions', function ($join): void {
                $join->on('submissions.organization_id', '=', 'links.organization_id')
                    ->on('submissions.id', '=', 'links.feedback_submission_id');
            })
            ->join('feedback_themes as themes', function ($join): void {
                $join->on('themes.organization_id', '=', 'links.organization_id')
                    ->on('themes.id', '=', 'links.feedback_theme_id');
            })
            ->where('links.organization_id', $organizationId)
            ->where('submissions.submitted_at', '>=', $period->startUtc)
            ->where('submissions.submitted_at', '<', $period->endUtc)
            ->when($client instanceof Client, fn ($query) => $query->where('submissions.client_id', $client->getKey()))
            ->select('themes.id', 'themes.name', 'themes.group_name')
            ->selectRaw('COUNT(DISTINCT links.client_id) as client_count')
            ->selectRaw('COUNT(DISTINCT links.feedback_submission_id) as submission_count')
            ->selectRaw('AVG(submissions.score) as average_score')
            ->groupBy('themes.id', 'themes.name', 'themes.group_name')
            ->orderByDesc('client_count')
            ->orderBy('themes.name')
            ->get()
            ->map(static fn (object $row): array => [
                'name' => (string) $row->name,
                'group' => $row->group_name !== null ? (string) $row->group_name : null,
                'client_count' => (int) $row->client_count,
                'submission_count' => (int) $row->submission_count,
                'average_score' => $row->average_score === null ? null : round((float) $row->average_score, 2),
            ])
            ->all();

        return new FeedbackAnalyticsData(
            submissionCount: (int) ($summary->submission_count ?? 0),
            averageScore: $summary?->average_score === null ? null : round((float) $summary->average_score, 2),
            themes: $themes,
        );
    }
}
