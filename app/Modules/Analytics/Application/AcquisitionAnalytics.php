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
    private const string UnknownSourceLabel = 'Не указан';

    private const string OtherSourceLabel = 'Другие';

    private const string SemanticKindUnknown = 'unknown';

    private const string SemanticKindReferral = 'referral';

    private const string SemanticKindDirect = 'direct';

    private const string SemanticKindUtm = 'utm';

    private const string BucketKindOverflow = 'overflow';

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
        $directSourceCondition = "attribution.source_type IN ('legacy', 'source', 'manual')
            AND NULLIF(TRIM(attribution.source), '') IS NOT NULL";
        $utmSourceCondition = "attribution.source_type = 'utm'
            AND NULLIF(TRIM(attribution.utm_source), '') IS NOT NULL";
        $semanticKind = "CASE
            WHEN attribution.source_type = 'referral' THEN '".self::SemanticKindReferral."'
            WHEN {$directSourceCondition} THEN '".self::SemanticKindDirect."'
            WHEN {$utmSourceCondition} THEN '".self::SemanticKindUtm."'
            ELSE '".self::SemanticKindUnknown."'
        END";
        $semanticValue = "CASE
            WHEN {$directSourceCondition} THEN attribution.source
            WHEN {$utmSourceCondition} THEN attribution.utm_source
            ELSE NULL
        END";
        $classified = DB::query()
            ->from($clientTable.' as clients')
            ->leftJoin($attributionTable.' as attribution', function (JoinClause $join): void {
                $join
                    ->on('attribution.organization_id', '=', 'clients.organization_id')
                    ->on('attribution.client_id', '=', 'clients.id');
            })
            ->where('clients.organization_id', $organizationId)
            ->where('clients.created_at', '>=', $period->startUtc)
            ->where('clients.created_at', '<', $period->endUtc)
            ->select(DB::raw($semanticKind.' as semantic_kind, '.$semanticValue.' as semantic_value'));

        $grouped = DB::query()
            ->fromSub($classified, 'classified_sources')
            ->selectRaw('semantic_kind, semantic_value, COUNT(*) as source_count')
            ->groupBy('semantic_kind', 'semantic_value');

        $presented = DB::query()
            ->fromSub($grouped, 'semantic_sources')
            ->select(DB::raw($this->presentedSelectExpression()));

        $ranked = DB::query()
            ->fromSub($presented, 'presented_sources')
            ->selectRaw("semantic_kind, semantic_value, source_count, source_label, is_unknown,
                ROW_NUMBER() OVER (
                    PARTITION BY is_unknown
                    ORDER BY source_count DESC, source_label ASC, semantic_kind ASC, COALESCE(semantic_value, '') ASC
                ) as source_rank");

        $bucketed = DB::query()
            ->fromSub($ranked, 'ranked_sources')
            ->selectRaw("CASE
                    WHEN is_unknown = 1 THEN '".self::SemanticKindUnknown."'
                    WHEN source_rank <= ".self::MaximumVisibleKnownSourceBuckets." THEN semantic_kind
                    ELSE '".self::BucketKindOverflow."'
                END as bucket_kind,
                CASE
                    WHEN is_unknown = 1 OR source_rank > ".self::MaximumVisibleKnownSourceBuckets." THEN NULL
                    ELSE semantic_value
                END as bucket_value,
                CASE
                    WHEN is_unknown = 1 THEN '".self::UnknownSourceLabel."'
                    WHEN source_rank <= ".self::MaximumVisibleKnownSourceBuckets." THEN source_label
                    ELSE '".self::OtherSourceLabel."'
                END as source_label,
                source_count");

        $rows = DB::query()
            ->fromSub($bucketed, 'bucketed_sources')
            ->selectRaw('bucket_kind, bucket_value, source_label, SUM(source_count) as source_count')
            ->groupBy('bucket_kind', 'bucket_value', 'source_label')
            ->orderByDesc('source_count')
            ->orderBy('source_label')
            ->limit(self::MaximumSourceResultBuckets)
            ->get()
            ->map(static fn (object $row): SourceBucket => new SourceBucket(
                label: (string) $row->source_label,
                count: (int) $row->source_count,
            ))
            ->all();

        return array_values($rows);
    }

    /** @return literal-string */
    private function presentedSelectExpression(): string
    {
        return <<<'SQL'
semantic_kind, semantic_value, source_count,
CASE
    WHEN semantic_kind = 'unknown' THEN 'Не указан'
    WHEN semantic_kind = 'referral' THEN 'Реферальный переход'
    WHEN semantic_kind = 'utm' THEN 'UTM: ' || semantic_value
    WHEN semantic_kind = 'direct' AND (
        semantic_value IN ('Не указан', 'Реферальный переход', 'Другие')
        OR semantic_value LIKE 'UTM: %'
        OR semantic_value LIKE 'Источник: %'
        OR semantic_value IN ('По рекомендации знакомых', 'Социальные сети', 'Поиск в интернете', 'Партнёр', 'Другое', 'Telegram', 'Email', 'Портал', 'Сайт', 'Instagram', 'Yandex')
    ) THEN 'Источник: ' || semantic_value
    WHEN semantic_kind = 'direct' THEN CASE
        WHEN LOWER(semantic_value) = 'friend' THEN 'По рекомендации знакомых'
        WHEN LOWER(semantic_value) = 'social' THEN 'Социальные сети'
        WHEN LOWER(semantic_value) = 'search' THEN 'Поиск в интернете'
        WHEN LOWER(semantic_value) = 'partner' THEN 'Партнёр'
        WHEN LOWER(semantic_value) = 'other' THEN 'Другое'
        WHEN LOWER(semantic_value) = 'telegram' THEN 'Telegram'
        WHEN LOWER(semantic_value) = 'email_auth' THEN 'Email'
        WHEN LOWER(semantic_value) = 'portal' THEN 'Портал'
        WHEN LOWER(semantic_value) IN ('site', 'web', 'website') THEN 'Сайт'
        WHEN LOWER(semantic_value) = 'instagram' THEN 'Instagram'
        WHEN LOWER(semantic_value) IN ('facebook', 'vk') THEN 'Социальные сети'
        WHEN LOWER(semantic_value) = 'yandex' THEN 'Yandex'
        WHEN LOWER(semantic_value) = 'google' THEN 'Google'
        ELSE semantic_value
    END
    ELSE semantic_value
END as source_label,
CASE WHEN semantic_kind = 'unknown' THEN 1 ELSE 0 END as is_unknown
SQL;
    }
}
