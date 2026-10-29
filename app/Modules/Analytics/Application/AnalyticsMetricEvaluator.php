<?php

namespace App\Modules\Analytics\Application;

use App\Models\User;
use App\Modules\Analytics\Application\Data\AnalyticsMetricFilters;
use App\Modules\Analytics\Application\Data\AnalyticsMetricResult;
use App\Modules\Analytics\Application\Data\AnalyticsMetricSourceValue;
use App\Modules\Analytics\Application\Data\DashboardPeriod;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricCalculation;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricComparison;
use App\Modules\Analytics\Domain\Enums\AnalyticsMetricSourceKind;
use App\Modules\Analytics\Domain\Models\AnalyticsMetric;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use Illuminate\Support\Collection;

final readonly class AnalyticsMetricEvaluator
{
    public function __construct(
        private OrganizationContext $context,
        private AnalyticsMetricSourceReader $sources,
        private AnalyticsMetricCalculator $calculator,
    ) {}

    /**
     * @param  Collection<int, AnalyticsMetric>|array<int, mixed>  $metrics
     * @return array<string, AnalyticsMetricResult>
     */
    public function evaluate(
        User $actor,
        DashboardPeriod $period,
        Collection|array $metrics,
        ?Client $client = null,
        ?AnalyticsMetricFilters $filters = null,
    ): array {
        $metrics = $metrics instanceof Collection ? $metrics->values()->all() : array_values($metrics);
        $organizationId = $this->context->id();
        $metrics = array_values(array_filter(
            $metrics,
            static fn (mixed $metric): bool => $metric instanceof AnalyticsMetric
                && (! $metric->exists || (int) $metric->organization_id === $organizationId),
        ));
        $filters ??= AnalyticsMetricFilters::empty();
        $allSources = $this->allSources($metrics);
        $snapshots = [];
        $previousPeriod = null;
        $needsPrevious = collect($metrics)->contains(
            static fn (AnalyticsMetric $metric): bool => $metric->comparison === AnalyticsMetricComparison::PreviousPeriod,
        );
        if ($needsPrevious) {
            $previousPeriod = $this->previousPeriod($period);
        }

        $results = [];
        $visiting = [];
        foreach ($metrics as $metric) {
            $results[$metric->stable_key] = $this->evaluateMetric(
                $metric,
                $actor,
                $period,
                $previousPeriod,
                $allSources,
                $snapshots,
                $client,
                $filters,
                $metrics,
                $results,
                $visiting,
            );
        }

        return $results;
    }

    /**
     * @param  list<string>  $allSources
     * @param  array<string, array<string, AnalyticsMetricSourceValue>>  $snapshots
     * @param  list<AnalyticsMetric>  $metrics
     * @param  array<string, AnalyticsMetricResult>  $results
     * @param  array<string, true>  $visiting
     */
    private function evaluateMetric(
        AnalyticsMetric $metric,
        User $actor,
        DashboardPeriod $period,
        ?DashboardPeriod $previousPeriod,
        array $allSources,
        array &$snapshots,
        ?Client $client,
        AnalyticsMetricFilters $filters,
        array $metrics,
        array &$results,
        array &$visiting,
    ): AnalyticsMetricResult {
        if (isset($visiting[$metric->stable_key])) {
            return $this->result($metric, null, null, 'Метрика содержит циклическое сравнение.');
        }

        $unsupportedFilter = $this->unsupportedFilter($metric, $filters);
        if ($unsupportedFilter !== null) {
            return $this->result($metric, null, null, $unsupportedFilter);
        }

        $visiting[$metric->stable_key] = true;
        $current = $this->snapshot($period, $actor, $client, $filters, $allSources, $snapshots);
        $a = $current[$metric->source_a_key] ?? null;
        $b = null;
        $currency = $a?->currency;

        if ($metric->comparison === AnalyticsMetricComparison::None) {
            $b = $metric->source_b_key === null ? null : ($current[$metric->source_b_key] ?? null);
        } elseif ($metric->comparison === AnalyticsMetricComparison::PreviousPeriod && $previousPeriod !== null) {
            $previous = $this->snapshot($previousPeriod, $actor, $client, $filters, $allSources, $snapshots);
            $b = $previous[$metric->source_a_key] ?? null;
        } elseif ($metric->comparison === AnalyticsMetricComparison::Target) {
            $b = $metric->target_value === null ? null : new AnalyticsMetricSourceValue(
                key: 'target',
                kind: $a instanceof AnalyticsMetricSourceValue ? $a->kind : AnalyticsMetricSourceKind::Number,
                value: (float) $metric->target_value,
                currency: $currency,
            );
        } elseif ($metric->comparison === AnalyticsMetricComparison::Metric && $metric->comparison_metric_id !== null) {
            $comparisonMetric = collect($metrics)->first(
                static fn (AnalyticsMetric $candidate): bool => (int) $candidate->getKey() === (int) $metric->comparison_metric_id,
            );
            if ($comparisonMetric instanceof AnalyticsMetric) {
                $comparisonResult = $results[$comparisonMetric->stable_key] ?? $this->evaluateMetric(
                    $comparisonMetric,
                    $actor,
                    $period,
                    $previousPeriod,
                    $allSources,
                    $snapshots,
                    $client,
                    $filters,
                    $metrics,
                    $results,
                    $visiting,
                );
                if ($comparisonResult->value === null
                    && $comparisonResult->message !== null
                    && $comparisonResult->message !== 'Недостаточно данных') {
                    unset($visiting[$metric->stable_key]);

                    return $this->result($metric, null, null, $comparisonResult->message);
                }
                $b = $comparisonResult->value === null ? null : new AnalyticsMetricSourceValue(
                    key: $comparisonMetric->stable_key,
                    kind: AnalyticsMetricSourceCatalog::kind($comparisonMetric->source_a_key)
                        ?? AnalyticsMetricSourceKind::Number,
                    value: $comparisonResult->value,
                    currency: $comparisonResult->currency,
                );
                $currency = $comparisonResult->currency ?? $currency;
            }
        }

        if ($a !== null && $b !== null && $a->kind !== $b->kind) {
            unset($visiting[$metric->stable_key]);

            return $this->result($metric, null, null, 'Выбранные источники имеют разные типы данных.');
        }

        $value = $this->calculator->calculate(
            $metric->calculation,
            $a?->value,
            $b?->value,
            $metric->null_policy,
            $metric->zero_policy,
        );
        unset($visiting[$metric->stable_key]);

        return $this->result(
            $metric,
            $value === null ? null : round($value, $metric->decimal_precision),
            $currency,
            $value === null ? 'Недостаточно данных' : null,
        );
    }

    /**
     * @param  list<string>  $sourceKeys
     * @param  array<string, array<string, AnalyticsMetricSourceValue>>  $snapshots
     * @return array<string, AnalyticsMetricSourceValue>
     */
    private function snapshot(
        DashboardPeriod $period,
        User $actor,
        ?Client $client,
        AnalyticsMetricFilters $filters,
        array $sourceKeys,
        array &$snapshots,
    ): array {
        $key = $period->startUtc->toIso8601String().'|'.$period->endUtc->toIso8601String();
        if (! array_key_exists($key, $snapshots)) {
            $snapshots[$key] = $this->sources->read($actor, $period, $client, $filters, $sourceKeys);
        }

        return $snapshots[$key];
    }

    private function unsupportedFilter(AnalyticsMetric $metric, AnalyticsMetricFilters $filters): ?string
    {
        $sourceKeys = [$metric->source_a_key, $metric->source_b_key];
        foreach (array_filter($sourceKeys, 'is_string') as $sourceKey) {
            $sourceFilter = AnalyticsMetricSourceCatalog::filter($sourceKey);
            foreach ($filters->values() as $filterKey => $filterValue) {
                if ($filterValue !== null && $filterValue !== '' && $filterKey !== $sourceFilter) {
                    return 'Выбранный фильтр не применяется к этой метрике.';
                }
            }
        }

        return null;
    }

    /**
     * @param  list<AnalyticsMetric>  $metrics
     * @return list<string>
     */
    private function allSources(array $metrics): array
    {
        $sources = [];
        foreach ($metrics as $metric) {
            $sources[] = $metric->source_a_key;
            if ($metric->source_b_key !== null) {
                $sources[] = $metric->source_b_key;
            }
        }

        return array_values(array_unique($sources));
    }

    private function previousPeriod(DashboardPeriod $period): DashboardPeriod
    {
        $seconds = $period->startUtc->diffInSeconds($period->endUtc);
        $start = $period->startUtc->subSeconds($seconds);
        $end = $period->startUtc;
        $timezone = new \DateTimeZone($period->timezone);

        return new DashboardPeriod(
            startUtc: $start,
            endUtc: $end,
            timezone: $period->timezone,
            startDate: $start->setTimezone($timezone)->format('Y-m-d'),
            endDate: $end->subSecond()->setTimezone($timezone)->format('Y-m-d'),
            preset: DashboardPeriod::Custom,
            nowUtc: $period->nowUtc->subSeconds($seconds),
        );
    }

    private function result(
        AnalyticsMetric $metric,
        ?float $value,
        ?string $currency,
        ?string $message,
    ): AnalyticsMetricResult {
        return new AnalyticsMetricResult(
            key: $metric->stable_key,
            name: $metric->name,
            unit: $metric->output_unit,
            precision: (int) $metric->decimal_precision,
            value: $value,
            currency: $currency,
            message: $message,
            signed: $metric->calculation === AnalyticsMetricCalculation::ChangePercent,
        );
    }
}
