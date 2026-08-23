<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiEvaluationComparison;
use App\Modules\AI\Application\Services\AiEvaluationRunMetricsReader;
use App\Modules\AI\Application\Services\AiEvaluationSnapshotHasher;
use App\Modules\AI\Domain\Models\AiEvalRun;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * @phpstan-type EvaluationSnapshot array{
 *     suite: array{id: int},
 *     capability: string,
 *     cases: list<array<string, mixed>>,
 *     prompt_version: array<string, mixed>,
 *     model_release: array<string, mixed>
 * }
 */
final class CompareAiEvaluationRuns
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly AiEvaluationRunMetricsReader $metricsReader,
        private readonly AiEvaluationSnapshotHasher $snapshotHasher,
    ) {}

    /** @param array<int|string, mixed> $runIds */
    public function handle(User $actor, array $runIds): AiEvaluationComparison
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);

        $ids = array_values(array_unique(array_filter(array_map(static function (mixed $value): ?int {
            if (is_int($value) && $value > 0) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }

            return null;
        }, $runIds))));
        if (count($ids) < 2 || count($ids) > 4) {
            throw new InvalidArgumentException('Выберите от двух до четырёх завершённых запусков.');
        }

        $runs = AiEvalRun::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', $ids)
            ->orderBy('created_at')
            ->get();
        if ($runs->count() !== count($ids)) {
            throw new AuthorizationException('Запуск проверки недоступен в текущей организации.');
        }

        $first = $runs->first();
        if (! $first instanceof AiEvalRun) {
            throw new InvalidArgumentException('Запуски проверки не найдены.');
        }

        $snapshots = [];
        foreach ($runs as $run) {
            $snapshot = $this->snapshot($run);
            if ($snapshot === null) {
                return new AiEvaluationComparison(false, 'У одного из запусков нет полного снимка примеров. Сначала выполните проверку заново.');
            }
            $snapshots[$run->getKey()] = $snapshot;
        }

        $firstSnapshot = $snapshots[$first->getKey()];
        foreach ($runs as $run) {
            $snapshot = $snapshots[$run->getKey()];
            if ($snapshot['suite']['id'] !== $firstSnapshot['suite']['id']
                || $snapshot['capability'] !== $firstSnapshot['capability']) {
                return new AiEvaluationComparison(false, 'Возможности AI в запусках различаются. Эти результаты нельзя сравнивать.');
            }
        }

        $caseDigest = $this->caseDigest($firstSnapshot['cases']);
        if ($caseDigest === null) {
            return new AiEvaluationComparison(false, 'У одного из запусков нет полного снимка примеров. Сначала выполните проверку заново.');
        }

        foreach ($runs as $run) {
            $runCaseDigest = $this->caseDigest($snapshots[$run->getKey()]['cases']);
            if ($runCaseDigest === null || ! hash_equals($caseDigest, $runCaseDigest)) {
                return new AiEvaluationComparison(false, 'Наборы примеров отличаются. Эти запуски нельзя сравнивать как одну проверку.');
            }
        }

        $metrics = $this->metricsReader->forRuns($runs);
        $summaries = array_values($runs->map(fn (AiEvalRun $run): array => $this->summary(
            $run,
            $snapshots[$run->getKey()],
            $metrics[$run->getKey()] ?? [],
        ))->all());

        return new AiEvaluationComparison(
            compatible: true,
            message: 'Сравнение запусков одной проверки',
            runs: $summaries,
        );
    }

    /** @param list<array<string, mixed>> $cases */
    private function caseDigest(array $cases): ?string
    {
        try {
            return $this->snapshotHasher->casesDigest($cases);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function summary(AiEvalRun $run, array $snapshot, array $metrics): array
    {
        $promptVersion = is_array($snapshot['prompt_version'] ?? null) ? $snapshot['prompt_version'] : [];
        $modelRelease = is_array($snapshot['model_release'] ?? null) ? $snapshot['model_release'] : [];
        $promptLabel = is_scalar($promptVersion['version'] ?? null) ? 'Промпт v'.(int) $promptVersion['version'] : 'Промпт недоступен';
        $modelLabel = is_scalar($modelRelease['provider'] ?? null) && is_scalar($modelRelease['model'] ?? null) && is_scalar($modelRelease['release_number'] ?? null)
            ? $modelRelease['provider'].' · '.$modelRelease['model'].' · выпуск '.$modelRelease['release_number']
            : 'Модель недоступна';

        return [
            'prompt_label' => $promptLabel,
            'model_label' => $modelLabel,
            'total_cases' => (int) $run->total_cases,
            'passed_cases' => (int) $run->passed_cases,
            'failed_cases' => (int) $run->failed_cases,
            'pass_percentage' => (float) $run->pass_percentage,
            'check_breakdown' => $metrics['case_breakdown'] ?? [],
            'assertion_breakdown' => $metrics['assertion_breakdown'] ?? [],
            'tokens' => $metrics['tokens'] ?? [],
            'rag' => $metrics['rag'] ?? [],
            'judge' => $metrics['judge'] ?? ['status' => 'disabled', 'label' => 'не настроена'],
            'human_review' => $metrics['human_review'] ?? [],
            'estimated_cost' => $metrics['cost']['estimated_by_currency'] ?? [],
            'provider_cost' => $metrics['cost']['provider_reported_by_currency'] ?? [],
            'estimated_cost_unknown_count' => (int) ($metrics['cost']['estimated_currency_unknown_count'] ?? 0),
            'provider_cost_unknown_count' => (int) ($metrics['cost']['provider_reported_unknown_count']
                ?? $metrics['cost']['provider_reported_currency_unknown_count']
                ?? 0),
            'average_latency_ms' => (int) ($run->average_latency_ms ?? 0),
            'retry_count' => (int) ($run->retry_count ?? 0),
            'failover_count' => (int) ($run->failover_count ?? 0),
            'execution_error_count' => (int) ($run->execution_error_count ?? 0),
        ];
    }

    /** @return EvaluationSnapshot|null */
    private function snapshot(AiEvalRun $run): ?array
    {
        $snapshot = $run->provenance_snapshot;
        $schemaVersion = is_array($snapshot) ? ($snapshot['schema_version'] ?? null) : null;
        $suite = is_array($snapshot) ? ($snapshot['suite'] ?? null) : null;
        $capability = is_array($snapshot) ? ($snapshot['capability'] ?? null) : null;
        $cases = is_array($snapshot) ? ($snapshot['cases'] ?? null) : null;
        $promptVersion = is_array($snapshot) ? ($snapshot['prompt_version'] ?? null) : null;
        $modelRelease = is_array($snapshot) ? ($snapshot['model_release'] ?? null) : null;
        $suiteId = $this->positiveId(is_array($suite) ? ($suite['id'] ?? null) : null);
        $promptVersionId = $this->positiveId(is_array($promptVersion) ? ($promptVersion['id'] ?? null) : null);
        $modelReleaseId = $this->positiveId(is_array($modelRelease) ? ($modelRelease['id'] ?? null) : null);

        if (! is_array($suite)
            || $suiteId === null
            || $suiteId !== (int) $run->eval_suite_id
            || (! is_int($schemaVersion) && ! (is_string($schemaVersion) && ctype_digit($schemaVersion)))
            || (int) $schemaVersion < 2
            || ! is_string($capability)
            || ! is_array($cases)
            || count($cases) > AiRuntimeLimits::PLATFORM_MAX_EVALUATION_CASES
            || ! is_array($promptVersion)
            || ! is_array($modelRelease)
            || $promptVersionId === null
            || $promptVersionId !== (int) $run->prompt_version_id
            || $modelReleaseId === null
            || $modelReleaseId !== (int) $run->model_release_id) {
            return null;
        }

        $normalizedCases = [];
        foreach ($cases as $case) {
            if (! is_array($case)) {
                return null;
            }

            if ($this->positiveId($case['id'] ?? null) === null
                || ! array_key_exists('assertions', $case)
                || ! is_array($case['assertions'])
                || ! array_key_exists('expected_output_schema', $case)
                || ($case['expected_output_schema'] !== null && ! is_array($case['expected_output_schema']))
                || ! is_string($case['test_inputs_digest'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/', $case['test_inputs_digest']) !== 1) {
                return null;
            }

            $normalizedCases[] = $case;
        }

        return [
            'suite' => ['id' => (int) $suite['id']],
            'capability' => $capability,
            'cases' => $normalizedCases,
            'prompt_version' => $promptVersion,
            'model_release' => $modelRelease,
        ];
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
