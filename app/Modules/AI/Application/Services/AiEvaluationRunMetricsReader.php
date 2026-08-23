<?php

namespace App\Modules\AI\Application\Services;

use App\Modules\AI\Domain\Models\AiEvalRun;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use Illuminate\Database\Eloquent\Collection;

final class AiEvaluationRunMetricsReader
{
    public function __construct(
        private readonly AiEvaluationRunMetricsAggregator $aggregator,
    ) {}

    /** @return array<string, mixed> */
    public function forRun(AiEvalRun $evaluationRun): array
    {
        $metrics = $this->forRuns(new Collection([$evaluationRun]));

        return $metrics[$evaluationRun->getKey()] ?? $this->storedMetrics($evaluationRun);
    }

    /**
     * @param  Collection<int, AiEvalRun>  $evaluationRuns
     * @return array<int, array<string, mixed>>
     */
    public function forRuns(Collection $evaluationRuns): array
    {
        if ($evaluationRuns->isEmpty()) {
            return [];
        }

        $storedMetrics = [];
        $runIdsByEvaluation = [];
        $allRunIds = [];

        foreach ($evaluationRuns as $evaluationRun) {
            $evaluationId = (int) $evaluationRun->getKey();
            $storedMetrics[$evaluationId] = $this->storedMetrics($evaluationRun);
            $runIds = $this->evaluationRunIds($evaluationRun);
            if ($runIds === []) {
                continue;
            }

            $runIdsByEvaluation[$evaluationId] = $runIds;
            foreach ($runIds as $runId) {
                $allRunIds[$runId] = $runId;
            }
        }

        if ($runIdsByEvaluation === []) {
            return $storedMetrics;
        }

        $runs = AiRun::query()
            ->where('organization_id', $evaluationRuns->first()->organization_id)
            ->whereIn('id', array_values($allRunIds))
            ->limit(AiRuntimeLimits::PLATFORM_MAX_EVALUATION_CASES * max(1, $evaluationRuns->count()))
            ->get();
        $existingRunIds = $runs->modelKeys();
        $reviewMetrics = $this->aggregator->humanReviewMetricsByEvaluationRunIds(
            (int) $evaluationRuns->first()->organization_id,
            array_map(
                static fn (array $runIds): array => array_values(array_intersect($runIds, $existingRunIds)),
                $runIdsByEvaluation,
            ),
        );

        foreach ($runIdsByEvaluation as $evaluationId => $_runIds) {
            $storedMetrics[$evaluationId]['human_review'] = $reviewMetrics[$evaluationId] ?? [
                'reviewed_cases' => 0,
                'accepted_count' => 0,
                'edited_and_accepted_count' => 0,
                'rejected_count' => 0,
                'accepted_rate' => 0.0,
                'edited_and_accepted_rate' => 0.0,
                'rejected_rate' => 0.0,
            ];
        }

        return $storedMetrics;
    }

    /** @return array<string, mixed> */
    private function storedMetrics(AiEvalRun $evaluationRun): array
    {
        return is_array($evaluationRun->metrics_payload) ? $evaluationRun->metrics_payload : [];
    }

    /** @return list<int> */
    private function evaluationRunIds(AiEvalRun $evaluationRun): array
    {
        $resultsPayload = $evaluationRun->results_payload;
        $cases = is_array($resultsPayload['cases'] ?? null) ? $resultsPayload['cases'] : [];
        $runIds = [];

        foreach (array_slice($cases, 0, AiRuntimeLimits::PLATFORM_MAX_EVALUATION_CASES) as $case) {
            if (! is_array($case)) {
                continue;
            }

            $runId = $case['ai_run_id'] ?? null;
            if (is_int($runId) && $runId > 0) {
                $runIds[$runId] = $runId;
            } elseif (is_string($runId) && ctype_digit($runId) && (int) $runId > 0) {
                $runIds[(int) $runId] = (int) $runId;
            }
        }

        return array_values($runIds);
    }
}
