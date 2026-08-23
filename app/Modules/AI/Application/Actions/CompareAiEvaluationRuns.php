<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiEvaluationComparison;
use App\Modules\AI\Domain\Models\AiEvalRun;
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
        foreach ($runs as $run) {
            if (! hash_equals($caseDigest, $this->caseDigest($snapshots[$run->getKey()]['cases']))) {
                return new AiEvaluationComparison(false, 'Наборы примеров отличаются. Эти запуски нельзя сравнивать как одну проверку.');
            }
        }

        $summaries = array_values($runs->map(fn (AiEvalRun $run): array => $this->summary($run, $snapshots[$run->getKey()]))->all());

        return new AiEvaluationComparison(
            compatible: true,
            message: 'Сравнение запусков одной проверки',
            runs: $summaries,
        );
    }

    private function caseDigest(mixed $cases): string
    {
        if (! is_array($cases)) {
            return hash('sha256', 'invalid');
        }

        return hash('sha256', (string) json_encode($cases, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function summary(AiEvalRun $run, array $snapshot): array
    {
        $metrics = is_array($run->metrics_payload) ? $run->metrics_payload : [];
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
            'rag' => $metrics['rag'] ?? [],
            'human_review' => $metrics['human_review'] ?? [],
            'estimated_cost' => $metrics['cost']['estimated_by_currency'] ?? [],
            'provider_cost' => $metrics['cost']['provider_reported_by_currency'] ?? [],
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
        $suite = is_array($snapshot) ? ($snapshot['suite'] ?? null) : null;
        $capability = is_array($snapshot) ? ($snapshot['capability'] ?? null) : null;
        $cases = is_array($snapshot) ? ($snapshot['cases'] ?? null) : null;
        $promptVersion = is_array($snapshot) ? ($snapshot['prompt_version'] ?? null) : null;
        $modelRelease = is_array($snapshot) ? ($snapshot['model_release'] ?? null) : null;

        if (! is_array($suite)
            || ! is_scalar($suite['id'] ?? null)
            || ! is_string($capability)
            || ! is_array($cases)
            || ! is_array($promptVersion)
            || ! is_array($modelRelease)) {
            return null;
        }

        $normalizedCases = [];
        foreach ($cases as $case) {
            if (! is_array($case)) {
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
}
