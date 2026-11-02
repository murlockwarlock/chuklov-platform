<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\ClientCohortFilters;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\DemographicsAnalyticsData;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final readonly class DemographicsAnalytics
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private ClientCohortQuery $cohorts,
        private ClientAgeRangeCatalog $ageRanges,
    ) {}

    public function handle(User $actor, DashboardPeriod $period, ?Client $client = null, ?ClientCohortFilters $cohortFilters = null): DemographicsAnalyticsData
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $organizationId = (int) $organization->getKey();
        if ($client instanceof Client && (int) $client->organization_id !== $organizationId) {
            abort(404);
        }
        $clientId = $client?->getKey();
        $clients = DB::table('clients')
            ->where('organization_id', $organizationId)
            ->when($clientId !== null, function ($query) use ($clientId, $period): void {
                $query
                    ->where('id', $clientId)
                    ->where('created_at', '>=', $period->startUtc)
                    ->where('created_at', '<', $period->endUtc);
            })
            ->when($cohortFilters !== null, function ($query) use ($cohortFilters, $period): void {
                $this->cohorts->constrain($query, 'clients.id', $cohortFilters, $period);
            })
            ->select(['id', 'birth_date', 'gender', 'country'])
            ->orderBy('id')
            ->get();

        $referenceDate = $period->endUtc->setTimezone($period->timezone)->subDay()->startOfDay();
        $ageCounts = [];
        $sexCounts = [];
        $countryCounts = [];
        $childrenCounts = [];
        foreach ($clients as $client) {
            $age = $this->age($client->birth_date, $referenceDate);
            $ageBucket = $age === null ? [
                'key' => null,
                'label' => 'Не указано',
                'unknown' => true,
            ] : $this->ageBucket($age, $organizationId);
            $sexBucket = match ((string) $client->gender) {
                'MALE' => ['key' => 'MALE', 'label' => 'Мужчины', 'unknown' => false],
                'FEMALE' => ['key' => 'FEMALE', 'label' => 'Женщины', 'unknown' => false],
                default => ['key' => null, 'label' => 'Не указано', 'unknown' => true],
            };
            $country = trim((string) ($client->country ?? ''));
            $countryBucket = $country === ''
                ? ['key' => null, 'label' => 'Не указана', 'unknown' => true]
                : ['key' => $country, 'label' => $country, 'unknown' => false];
            $childrenBucket = $age === null
                ? ['key' => null, 'label' => 'Не указано', 'unknown' => true]
                : ($age < 18
                    ? ['key' => 'children', 'label' => 'Дети', 'unknown' => false]
                    : ['key' => 'adult', 'label' => 'Взрослые', 'unknown' => false]);
            $this->incrementBucket($ageCounts, $ageBucket);
            $this->incrementBucket($sexCounts, $sexBucket);
            $this->incrementBucket($countryCounts, $countryBucket);
            $this->incrementBucket($childrenCounts, $childrenBucket);
        }

        $total = count($clients);
        $countryCount = count(array_filter(
            $countryCounts,
            static fn (array $bucket): bool => $bucket['unknown'] === false,
        ));

        return new DemographicsAnalyticsData(
            clientCount: $total,
            countryCount: $countryCount,
            age: $this->distribution($ageCounts, $total),
            sex: $this->distribution($sexCounts, $total),
            country: $this->distribution($countryCounts, $total),
            children: $this->distribution($childrenCounts, $total),
        );
    }

    /** @param array<string, array{key: string|null, label: string, unknown: bool, count: int}> $counts
     * @return list<array{key: string|null, label: string, unknown: bool, count: int, percent: float}>
     */
    private function distribution(array $counts, int $total): array
    {
        uasort($counts, static fn (array $left, array $right): int => $right['count'] <=> $left['count']);

        return array_map(
            static fn (array $bucket): array => [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'unknown' => $bucket['unknown'],
                'count' => $bucket['count'],
                'percent' => $total === 0 ? 0.0 : round($bucket['count'] * 100 / $total, 2),
            ],
            array_values($counts),
        );
    }

    /** @param array<string, array{key: string|null, label: string, unknown: bool, count: int}> $counts
     * @param  array{key: string|null, label: string, unknown: bool}  $bucket
     */
    private function incrementBucket(array &$counts, array $bucket): void
    {
        $bucketKey = $bucket['key'] ?? '__unknown__';
        $counts[$bucketKey] ??= [
            'key' => $bucket['key'],
            'label' => $bucket['label'],
            'unknown' => $bucket['unknown'],
            'count' => 0,
        ];
        $counts[$bucketKey]['count']++;
    }

    private function age(mixed $birthDate, CarbonImmutable $referenceDate): ?int
    {
        if (! is_string($birthDate) || trim($birthDate) === '') {
            return null;
        }
        try {
            return (int) CarbonImmutable::parse($birthDate, new DateTimeZone($referenceDate->getTimezone()->getName()))->diffInYears($referenceDate);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{key: string|null, label: string, unknown: bool} */
    private function ageBucket(int $age, int $organizationId): array
    {
        foreach ($this->ageRanges->rows($organizationId, true) as $range) {
            if ($age >= $range['min'] && ($range['max'] === null || $age <= $range['max'])) {
                return ['key' => $range['key'], 'label' => $range['label'], 'unknown' => false];
            }
        }

        return ['key' => null, 'label' => 'Не указано', 'unknown' => true];
    }
}
