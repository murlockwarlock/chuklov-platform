<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\AcquisitionAnalyticsData;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\SourceBucket;
use App\Modules\Attribution\Domain\Models\ClientAttribution;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class AcquisitionAnalytics
{
    private const int MaximumVisibleKnownSourceBuckets = 8;

    private const int MaximumSourceResultBuckets = self::MaximumVisibleKnownSourceBuckets + 2;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, DashboardPeriod $period): AcquisitionAnalyticsData
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);

        $organizationId = (int) $organization->getKey();
        $clientTable = (new Client)->getTable();

        $newClients = DB::table($clientTable)
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtc)
            ->count();

        return new AcquisitionAnalyticsData(
            newClients: $newClients,
            sources: $this->sourceBuckets($organizationId, $period, $clientTable),
        );
    }

    /** @return list<SourceBucket> */
    private function sourceBuckets(int $organizationId, DashboardPeriod $period, string $clientTable): array
    {
        $attributionTable = (new ClientAttribution)->getTable();
        $sourceLabel = "CASE
            WHEN attribution.source_type = 'referral' THEN 'Реферальный переход'
            WHEN attribution.source_type IN ('legacy', 'source', 'manual')
                AND NULLIF(TRIM(attribution.source), '') IS NOT NULL THEN attribution.source
            WHEN attribution.source_type = 'utm'
                AND NULLIF(TRIM(attribution.utm_source), '') IS NOT NULL THEN 'UTM: ' || attribution.utm_source
            ELSE 'Не указан'
        END";

        $grouped = DB::query()
            ->from($clientTable.' as clients')
            ->leftJoin($attributionTable.' as attribution', function (JoinClause $join): void {
                $join
                    ->on('attribution.organization_id', '=', 'clients.organization_id')
                    ->on('attribution.client_id', '=', 'clients.id');
            })
            ->where('clients.organization_id', $organizationId)
            ->where('clients.created_at', '>=', $period->startUtc)
            ->where('clients.created_at', '<', $period->endUtc)
            ->selectRaw($sourceLabel.' as source_label, COUNT(*) as source_count')
            ->groupByRaw($sourceLabel);

        $classified = DB::query()
            ->fromSub($grouped, 'source_buckets')
            ->selectRaw("source_label, source_count, CASE WHEN source_label = 'Не указан' THEN 1 ELSE 0 END as is_unknown");

        $ranked = DB::query()
            ->fromSub($classified, 'classified_sources')
            ->selectRaw('source_label, source_count, is_unknown, ROW_NUMBER() OVER (PARTITION BY is_unknown ORDER BY source_count DESC, source_label ASC) as source_rank');

        $bucketLabel = 'CASE WHEN is_unknown = 1 THEN source_label WHEN source_rank <= '.self::MaximumVisibleKnownSourceBuckets.' THEN source_label ELSE NULL END';

        return array_values(DB::query()
            ->fromSub($ranked, 'ranked_sources')
            ->selectRaw($bucketLabel.' as source_label, SUM(source_count) as source_count')
            ->groupByRaw($bucketLabel)
            ->orderByDesc('source_count')
            ->orderBy('source_label')
            ->limit(self::MaximumSourceResultBuckets)
            ->get()
            ->map(static fn (object $row): SourceBucket => new SourceBucket(
                label: $row->source_label === null ? 'Другие' : (string) $row->source_label,
                count: (int) $row->source_count,
            ))
            ->all());
    }
}
