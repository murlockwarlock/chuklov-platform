<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyComparison;
use Carbon\CarbonImmutable;

final readonly class CompareSurveyAttempts
{
    public function __construct(private RecordScenarioEvent $events) {}

    public function handle(SurveyAttempt $current): ?SurveyComparison
    {
        $previous = SurveyAttempt::query()
            ->where('organization_id', $current->organization_id)
            ->where('client_id', $current->client_id)
            ->where('survey_definition_id', $current->survey_definition_id)
            ->whereNot('id', $current->getKey())
            ->whereNotNull('completed_at')
            ->where(function ($query) use ($current): void {
                $query->where('completed_at', '<', $current->completed_at)
                    ->orWhere(function ($query) use ($current): void {
                        $query->where('completed_at', $current->completed_at)
                            ->where('id', '<', $current->getKey());
                    });
            })
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
        if ($previous === null) {
            return null;
        }

        $comparisonConfig = $current->scoring_snapshot['comparison'] ?? null;
        $comparable = is_array($comparisonConfig)
            && is_string($current->metric_schema_key)
            && $current->metric_schema_key !== ''
            && $current->metric_schema_key === $previous->metric_schema_key;
        $status = 'not_comparable';
        $deltas = [];
        if ($comparable) {
            $metricKeys = array_values(array_filter($comparisonConfig['metric_keys'] ?? [], 'is_string'));
            $status = $metricKeys === [] ? 'not_comparable' : 'stagnation_detected';
            foreach ($metricKeys as $metricKey) {
                $before = $previous->result_snapshot['metrics'][$metricKey]['value'] ?? null;
                $after = $current->result_snapshot['metrics'][$metricKey]['value'] ?? null;
                if (! is_numeric($before) || ! is_numeric($after)) {
                    $status = 'not_comparable';
                    break;
                }
                $delta = (float) $after - (float) $before;
                $deltas[$metricKey] = ['before' => (float) $before, 'after' => (float) $after, 'delta' => $delta];
                if ($delta < 0) {
                    $status = 'improved';
                }
            }
            if (($comparisonConfig['operator'] ?? null) !== 'no_decrease') {
                $status = 'not_comparable';
            }
        }

        $comparison = SurveyComparison::query()->firstOrCreate(
            ['organization_id' => $current->organization_id, 'current_attempt_id' => $current->getKey()],
            [
                'client_id' => $current->client_id,
                'previous_attempt_id' => $previous->getKey(),
                'status' => $status,
                'comparison_snapshot' => [
                    'metric_schema_key' => $current->metric_schema_key,
                    'configuration' => $comparisonConfig,
                    'metrics' => $deltas,
                ],
            ],
        );

        if ($comparison->status === 'stagnation_detected' && $comparison->scenario_event_id === null) {
            $event = $this->events->testStagnationDetected($current, $previous, CarbonImmutable::now());
            $comparison->forceFill(['scenario_event_id' => $event->getKey()])->save();
        }

        return $comparison->refresh();
    }
}
