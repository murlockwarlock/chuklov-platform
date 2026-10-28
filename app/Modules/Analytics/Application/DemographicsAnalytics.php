<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Application\Data\DemographicsAnalyticsData;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class DemographicsAnalytics
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
    ) {}

    public function handle(User $actor, DashboardPeriod $period): DemographicsAnalyticsData
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $clients = DB::table('clients')
            ->where('organization_id', $organization->getKey())
            ->where('created_at', '>=', $period->startUtc)
            ->where('created_at', '<', $period->endUtc)
            ->select(['birth_date', 'gender', 'country'])
            ->orderBy('id')
            ->get();

        $referenceDate = $period->endUtc->setTimezone('UTC')->startOfDay();
        $ageCounts = [];
        $sexCounts = [];
        $countryCounts = [];
        foreach ($clients as $client) {
            $ageLabel = $this->ageLabel($client->birth_date, $referenceDate);
            $sexLabel = match ((string) $client->gender) {
                'MALE' => 'Мужчины',
                'FEMALE' => 'Женщины',
                default => 'Не указано',
            };
            $country = trim((string) ($client->country ?? ''));
            $countryLabel = $country === '' ? 'Не указана' : $country;
            $ageCounts[$ageLabel] = ($ageCounts[$ageLabel] ?? 0) + 1;
            $sexCounts[$sexLabel] = ($sexCounts[$sexLabel] ?? 0) + 1;
            $countryCounts[$countryLabel] = ($countryCounts[$countryLabel] ?? 0) + 1;
        }

        $total = count($clients);

        return new DemographicsAnalyticsData(
            clientCount: $total,
            age: $this->distribution($ageCounts, $total),
            sex: $this->distribution($sexCounts, $total),
            country: $this->distribution($countryCounts, $total),
        );
    }

    /** @param array<string, int> $counts
     * @return list<array{label: string, count: int, percent: float}>
     */
    private function distribution(array $counts, int $total): array
    {
        arsort($counts);

        return array_values(array_map(
            static fn (string $label, int $count): array => [
                'label' => $label,
                'count' => $count,
                'percent' => $total === 0 ? 0.0 : round($count * 100 / $total, 2),
            ],
            array_keys($counts),
            array_values($counts),
        ));
    }

    private function ageLabel(mixed $birthDate, CarbonImmutable $referenceDate): string
    {
        if (! is_string($birthDate) || trim($birthDate) === '') {
            return 'Не указано';
        }
        try {
            $age = CarbonImmutable::parse($birthDate, 'UTC')->diffInYears($referenceDate);
        } catch (\Throwable) {
            return 'Не указано';
        }

        return match (true) {
            $age < 18 => 'Дети',
            $age < 30 => '18–29',
            $age < 45 => '30–44',
            $age < 60 => '45–59',
            default => '60+',
        };
    }
}
