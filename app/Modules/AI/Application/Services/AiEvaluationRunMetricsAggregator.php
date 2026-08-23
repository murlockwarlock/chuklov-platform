<?php

namespace App\Modules\AI\Application\Services;

use App\Modules\AI\Application\Data\AiEvaluationCaseResult;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunHumanReview;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

final class AiEvaluationRunMetricsAggregator
{
    /**
     * @param  Collection<int, AiRun>  $runs
     * @param  list<AiEvaluationCaseResult>  $caseResults
     * @return array<string, mixed>
     */
    public function aggregate(int $organizationId, Collection $runs, array $caseResults): array
    {
        $runIds = $runs->modelKeys();
        $attempts = AiRunAttempt::query()
            ->where('organization_id', $organizationId)
            ->whereIn('ai_run_id', $runIds)
            ->orderBy('attempt_number')
            ->get();
        $reviews = AiRunHumanReview::query()
            ->where('organization_id', $organizationId)
            ->whereIn('ai_run_id', $runIds)
            ->orderBy('review_step')
            ->get();

        $totalCases = count($caseResults);
        $passedCases = count(array_filter($caseResults, static fn (AiEvaluationCaseResult $result): bool => $result->passed));
        $failedCases = $totalCases - $passedCases;
        $totalLatency = $runs->sum(static fn (AiRun $run): int => max(0, (int) $run->latency_ms));
        $tokens = [
            'prompt_tokens' => $runs->sum(static fn (AiRun $run): int => $run->getTokenUsage()->promptTokens),
            'completion_tokens' => $runs->sum(static fn (AiRun $run): int => $run->getTokenUsage()->completionTokens),
            'total_tokens' => $runs->sum(static fn (AiRun $run): int => $run->getTokenUsage()->totalTokens),
        ];
        $estimatedCosts = [];
        foreach ($runs as $run) {
            $currency = strtoupper((string) ($run->cost_currency ?: 'USD'));
            if ($run->settled_estimated_cost_minor_units !== null) {
                $estimatedCosts[$currency] = ($estimatedCosts[$currency] ?? 0) + (int) $run->settled_estimated_cost_minor_units;
            }
        }

        $providerCosts = [];
        foreach ($attempts as $attempt) {
            if ($attempt->provider_cost_minor_units === null) {
                continue;
            }

            $snapshot = $attempt->pricing_snapshot;
            $currency = strtoupper((string) ($snapshot['currency'] ?? 'USD'));
            $providerCosts[$currency] = ($providerCosts[$currency] ?? 0) + (int) $attempt->provider_cost_minor_units;
        }

        $categoryBreakdown = [
            'passed' => $passedCases,
            'execution' => 0,
            'assertion' => 0,
            'schema' => 0,
            'rag' => 0,
            'judge' => 0,
        ];
        foreach ($caseResults as $result) {
            if ($result->passed) {
                continue;
            }

            $category = $result->failureCategory ?? 'execution';
            $categoryBreakdown[$category] = ($categoryBreakdown[$category] ?? 0) + 1;
        }

        $assertionBreakdown = [
            'by_category' => [],
            'by_type' => [],
        ];
        foreach ($caseResults as $result) {
            foreach ($result->assertions as $assertion) {
                $category = (string) ($assertion['category'] ?? 'assertion');
                $type = (string) ($assertion['type'] ?? 'unknown');
                $passed = (bool) ($assertion['passed'] ?? false);
                foreach ([['key' => 'by_category', 'value' => $category], ['key' => 'by_type', 'value' => $type]] as $dimension) {
                    $dimensionKey = $dimension['key'];
                    $value = $dimension['value'];
                    $assertionBreakdown[$dimensionKey][$value] ??= ['total' => 0, 'passed' => 0, 'failed' => 0];
                    $assertionBreakdown[$dimensionKey][$value]['total']++;
                    $assertionBreakdown[$dimensionKey][$value][$passed ? 'passed' : 'failed']++;
                }
            }
        }

        $latestReviews = $reviews
            ->groupBy('ai_run_id')
            ->map(static function (Collection $items): AiRunHumanReview {
                $review = $items->sortByDesc('review_step')->first();
                if (! $review instanceof AiRunHumanReview) {
                    throw new LogicException('Human review collection cannot be empty.');
                }

                return $review;
            });
        $reviewCounts = [
            'reviewed_cases' => $latestReviews->count(),
            'accepted_count' => $latestReviews->filter(static fn (AiRunHumanReview $review): bool => $review->decision->value === 'accepted')->count(),
            'edited_and_accepted_count' => $latestReviews->filter(static fn (AiRunHumanReview $review): bool => $review->decision->value === 'edited_and_accepted')->count(),
            'rejected_count' => $latestReviews->filter(static fn (AiRunHumanReview $review): bool => $review->decision->value === 'rejected')->count(),
        ];
        $reviewedCases = max(1, (int) $reviewCounts['reviewed_cases']);
        $reviewCounts['accepted_rate'] = round($reviewCounts['accepted_count'] / $reviewedCases * 100, 2);
        $reviewCounts['edited_and_accepted_rate'] = round($reviewCounts['edited_and_accepted_count'] / $reviewedCases * 100, 2);
        $reviewCounts['rejected_rate'] = round($reviewCounts['rejected_count'] / $reviewedCases * 100, 2);

        $retryCount = 0;
        $failoverCount = 0;
        foreach ($attempts->groupBy('ai_run_id') as $runAttempts) {
            $retryCount += max(0, $runAttempts->count() - 1);
            $failoverCount += $runAttempts->filter(static fn (AiRunAttempt $attempt): bool => str_contains(strtolower((string) $attempt->retry_or_failover_reason), 'failover'))->count();
        }

        $ragPassed = count(array_filter($caseResults, static fn (AiEvaluationCaseResult $result): bool => ($result->rag['checks_present'] ?? false) && $result->failureCategory !== 'rag'));
        $ragFailed = count(array_filter($caseResults, static fn (AiEvaluationCaseResult $result): bool => $result->failureCategory === 'rag'));
        $estimatedCurrency = count($estimatedCosts) === 1 ? array_key_first($estimatedCosts) : null;
        $providerCurrency = count($providerCosts) === 1 ? array_key_first($providerCosts) : null;

        return [
            'pass_percentage' => $totalCases === 0 ? 0.0 : round($passedCases / $totalCases * 100, 2),
            'case_breakdown' => $categoryBreakdown,
            'assertion_breakdown' => $assertionBreakdown,
            'latency' => [
                'total_ms' => $totalLatency,
                'average_ms' => $runs->isEmpty() ? 0 : (int) round($totalLatency / $runs->count()),
            ],
            'tokens' => $tokens,
            'cost' => [
                'estimated_by_currency' => $estimatedCosts,
                'provider_reported_by_currency' => $providerCosts,
                'estimated_label' => 'Расчётная стоимость Chuklov',
                'provider_label' => 'Стоимость от провайдера',
            ],
            'reliability' => [
                'retry_count' => $retryCount,
                'failover_count' => $failoverCount,
                'execution_error_count' => $categoryBreakdown['execution'],
                'error_rate' => $totalCases === 0 ? 0.0 : round($categoryBreakdown['execution'] / $totalCases * 100, 2),
            ],
            'rag' => [
                'checked_cases' => $ragPassed + $ragFailed,
                'passed_cases' => $ragPassed,
                'failed_cases' => $ragFailed,
            ],
            'human_review' => $reviewCounts,
            'judge' => [
                'status' => 'disabled',
                'label' => 'Дополнительная оценка не настроена',
            ],
            'single_currency' => [
                'estimated' => $estimatedCurrency,
                'provider' => $providerCurrency,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    public function columns(array $metrics): array
    {
        $estimated = $metrics['cost']['estimated_by_currency'] ?? [];
        $provider = $metrics['cost']['provider_reported_by_currency'] ?? [];

        return [
            'estimated_cost_minor_units' => count($estimated) === 1 ? (int) array_values($estimated)[0] : null,
            'provider_cost_minor_units' => count($provider) === 1 ? (int) array_values($provider)[0] : null,
            'total_latency_ms' => (int) ($metrics['latency']['total_ms'] ?? 0),
            'average_latency_ms' => (int) ($metrics['latency']['average_ms'] ?? 0),
            'total_prompt_tokens' => (int) ($metrics['tokens']['prompt_tokens'] ?? 0),
            'total_completion_tokens' => (int) ($metrics['tokens']['completion_tokens'] ?? 0),
            'retry_count' => (int) ($metrics['reliability']['retry_count'] ?? 0),
            'failover_count' => (int) ($metrics['reliability']['failover_count'] ?? 0),
            'execution_error_count' => (int) ($metrics['reliability']['execution_error_count'] ?? 0),
            'rag_failed_cases' => (int) ($metrics['rag']['failed_cases'] ?? 0),
            'human_reviewed_cases' => (int) ($metrics['human_review']['reviewed_cases'] ?? 0),
        ];
    }
}
