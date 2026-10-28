<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\PainAnalyticsData;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProblemTag;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Support\Facades\DB;

final readonly class PainAnalytics
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(
        User $actor,
        DashboardPeriod $period,
        ?Client $client = null,
        ?int $problemTagId = null,
    ): PainAnalyticsData {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $organizationId = (int) $organization->getKey();

        if ($client instanceof Client && (int) $client->organization_id !== $organizationId) {
            abort(404);
        }
        if ($problemTagId !== null && ! MedicalProblemTag::query()
            ->where('organization_id', $organizationId)
            ->whereKey($problemTagId)
            ->exists()) {
            abort(404);
        }
        $clientId = $client?->getKey();

        $sessionClientFilter = $clientId !== null ? ' AND sessions.client_id = :client_id' : '';
        $bindings = [
            'organization_id' => $organizationId,
            'start_at' => $period->startUtc,
            'end_at' => $period->endUtc,
        ];
        if ($clientId !== null) {
            $bindings['client_id'] = (int) $clientId;
        }
        $tagFilter = '';
        if ($problemTagId !== null) {
            $tagFilter = ' AND pain_tags.medical_problem_tag_id = :problem_tag_id';
            $bindings['problem_tag_id'] = $problemTagId;
        }

        $rows = DB::select(
            <<<SQL
            WITH ranked_sessions AS (
                SELECT
                    sessions.organization_id,
                    sessions.client_id,
                    sessions.id,
                    sessions.occurred_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY sessions.client_id
                        ORDER BY sessions.occurred_at ASC, sessions.id ASC
                    ) AS session_number
                FROM medical_sessions AS sessions
                WHERE sessions.organization_id = :organization_id
                    {$sessionClientFilter}
            ), ranked_pains AS (
                SELECT
                    pains.client_id,
                    pains.before_score,
                    pains.after_score,
                    tags.id AS tag_id,
                    tags.name AS tag_name,
                    tags.group_name AS tag_group,
                    ranked_sessions.session_number
                FROM medical_session_pains AS pains
                INNER JOIN ranked_sessions
                    ON ranked_sessions.organization_id = pains.organization_id
                    AND ranked_sessions.client_id = pains.client_id
                    AND ranked_sessions.id = pains.medical_session_id
                INNER JOIN medical_session_pain_tags AS pain_tags
                    ON pain_tags.organization_id = pains.organization_id
                    AND pain_tags.client_id = pains.client_id
                    AND pain_tags.medical_session_pain_id = pains.id
                    {$tagFilter}
                INNER JOIN medical_problem_tags AS tags
                    ON tags.organization_id = pain_tags.organization_id
                    AND tags.id = pain_tags.medical_problem_tag_id
                WHERE ranked_sessions.occurred_at >= :start_at
                    AND ranked_sessions.occurred_at < :end_at
            )
            SELECT
                tag_id,
                tag_name,
                tag_group,
                COUNT(DISTINCT client_id) AS client_count,
                COUNT(*) AS pain_count,
                AVG(before_score) AS average_before,
                AVG(after_score) AS average_after,
                AVG(CASE WHEN before_score IS NOT NULL AND after_score IS NOT NULL THEN before_score - after_score END) AS average_reduction,
                AVG(CASE WHEN session_number = 1 THEN before_score END) AS session_one_before,
                AVG(CASE WHEN session_number = 1 THEN after_score END) AS session_one_after,
                AVG(CASE WHEN session_number = 2 THEN before_score END) AS session_two_before,
                AVG(CASE WHEN session_number = 2 THEN after_score END) AS session_two_after,
                AVG(CASE WHEN session_number = 3 THEN before_score END) AS session_three_before,
                AVG(CASE WHEN session_number = 3 THEN after_score END) AS session_three_after
            FROM ranked_pains
            GROUP BY tag_id, tag_name, tag_group
            ORDER BY client_count DESC, tag_name ASC
            SQL,
            $bindings,
        );

        $sessionQuery = DB::table('medical_sessions')
            ->where('organization_id', $organizationId)
            ->where('occurred_at', '>=', $period->startUtc)
            ->where('occurred_at', '<', $period->endUtc)
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->when($problemTagId !== null, function ($query) use ($organizationId, $problemTagId): void {
                $query->whereExists(function ($subquery) use ($organizationId, $problemTagId): void {
                    $subquery
                        ->selectRaw('1')
                        ->from('medical_session_pains as filtered_pains')
                        ->join('medical_session_pain_tags as filtered_tags', function ($join) use ($organizationId, $problemTagId): void {
                            $join->on('filtered_tags.medical_session_pain_id', '=', 'filtered_pains.id')
                                ->where('filtered_tags.organization_id', $organizationId)
                                ->where('filtered_tags.medical_problem_tag_id', $problemTagId);
                        })
                        ->whereColumn('filtered_pains.medical_session_id', 'medical_sessions.id')
                        ->where('filtered_pains.organization_id', $organizationId)
                        ->whereColumn('filtered_pains.client_id', 'medical_sessions.client_id');
                });
            });
        $sessionCount = (clone $sessionQuery)->count();
        $clientCount = (clone $sessionQuery)->distinct('client_id')->count('client_id');

        return new PainAnalyticsData(
            clientCount: $clientCount,
            sessionCount: $sessionCount,
            problems: array_values(array_map(static function (object $row): array {
                $values = get_object_vars($row);

                return [
                    'tag_id' => (int) ($values['tag_id'] ?? 0),
                    'name' => (string) ($values['tag_name'] ?? ''),
                    'group' => isset($values['tag_group']) ? (string) $values['tag_group'] : null,
                    'client_count' => (int) ($values['client_count'] ?? 0),
                    'pain_count' => (int) ($values['pain_count'] ?? 0),
                    'average_before' => self::nullableFloat($values['average_before'] ?? null),
                    'average_after' => self::nullableFloat($values['average_after'] ?? null),
                    'average_reduction' => self::nullableFloat($values['average_reduction'] ?? null),
                    'session_1_before' => self::nullableFloat($values['session_one_before'] ?? null),
                    'session_1_after' => self::nullableFloat($values['session_one_after'] ?? null),
                    'session_2_before' => self::nullableFloat($values['session_two_before'] ?? null),
                    'session_2_after' => self::nullableFloat($values['session_two_after'] ?? null),
                    'session_3_before' => self::nullableFloat($values['session_three_before'] ?? null),
                    'session_3_after' => self::nullableFloat($values['session_three_after'] ?? null),
                ];
            }, $rows)),
        );
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }
}
