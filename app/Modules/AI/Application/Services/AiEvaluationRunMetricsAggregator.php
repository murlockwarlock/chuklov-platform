<?php

namespace App\Modules\AI\Application\Services;

use App\Modules\AI\Application\Data\AiEvaluationCaseResult;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunHumanReview;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

final class AiEvaluationRunMetricsAggregator
{
    /**
     * @param  Collection<int, AiRun>  $runs
     * @param  list<AiEvaluationCaseResult>  $caseResults
     * @return array<string, mixed>
     */
    public function aggregate(int $organizationId, Collection $runs, array $caseResults): array
    {
        /** @var list<int> $runIds */
        $runIds = array_values(array_map(
            static fn (int|string $id): int => (int) $id,
            $runs->modelKeys(),
        ));
        $attempts = AiRunAttempt::query()
            ->where('organization_id', $organizationId)
            ->whereIn('ai_run_id', $runIds)
            ->orderBy('attempt_number')
            ->limit(max(1, count($runIds)) * AiRuntimeLimits::PLATFORM_MAX_FAILOVER_ATTEMPTS)
            ->get();
        $attemptsByRun = $attempts->groupBy('ai_run_id');
        $reviews = $this->latestReviews($organizationId, $runIds);

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
        $estimatedUnknownCount = 0;
        foreach ($runs as $run) {
            if ($run->settled_estimated_cost_minor_units === null) {
                $estimatedUnknownCount++;

                continue;
            }

            $currency = $this->estimatedCurrency($run, $attemptsByRun->get($run->getKey(), new Collection));
            if ($currency === null) {
                $estimatedUnknownCount++;

                continue;
            }

            $estimatedCosts[$currency] = ($estimatedCosts[$currency] ?? 0) + (int) $run->settled_estimated_cost_minor_units;
        }

        $providerCosts = [];
        $providerUnknownCount = 0;
        $providerCurrencyUnknownCount = 0;
        foreach ($attempts as $attempt) {
            if ($attempt->provider_cost_minor_units === null) {
                $providerUnknownCount++;

                continue;
            }

            $snapshot = $attempt->pricing_snapshot;
            $currency = $this->currency($snapshot['currency'] ?? null);
            if ($currency === null) {
                $providerUnknownCount++;
                $providerCurrencyUnknownCount++;

                continue;
            }

            $providerCosts[$currency] = ($providerCosts[$currency] ?? 0) + (int) $attempt->provider_cost_minor_units;
        }

        $knownRunIds = array_fill_keys($runIds, true);
        foreach ($caseResults as $caseResult) {
            if (! isset($knownRunIds[$caseResult->aiRunId])) {
                $estimatedUnknownCount++;
                $providerUnknownCount++;
            }
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

        $reviewCounts = $this->humanReviewMetrics($reviews);

        $retryCount = 0;
        $failoverCount = 0;
        foreach ($attempts->groupBy('ai_run_id') as $runAttempts) {
            $previousIdentity = null;
            foreach ($runAttempts->sortBy('attempt_number')->values() as $attempt) {
                $identity = $this->attemptIdentity($attempt);
                if ($previousIdentity !== null) {
                    if ($identity === $previousIdentity) {
                        $retryCount++;
                    } else {
                        $failoverCount++;
                    }
                }

                $previousIdentity = $identity;
            }
        }

        $ragPassed = 0;
        $ragFailed = 0;
        $ragUnchecked = 0;
        foreach ($caseResults as $result) {
            if (! ($result->rag['checks_present'] ?? false)) {
                continue;
            }

            $ragAssertions = collect($result->assertions)->filter(
                static fn (array $assertion): bool => ($assertion['category'] ?? null) === 'rag',
            );
            if ($ragAssertions->isEmpty()) {
                $ragUnchecked++;

                continue;
            }

            $hasFailedRagAssertion = $ragAssertions->contains(
                static fn (array $assertion): bool => ! ($assertion['passed'] ?? false),
            );
            if ($hasFailedRagAssertion) {
                $ragFailed++;
            } else {
                $ragPassed++;
            }
        }
        $estimatedCurrency = $estimatedUnknownCount === 0 && count($estimatedCosts) === 1 ? array_key_first($estimatedCosts) : null;
        $providerCurrency = $providerUnknownCount === 0 && count($providerCosts) === 1 ? array_key_first($providerCosts) : null;

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
                'estimated_currency_unknown_count' => $estimatedUnknownCount,
                'provider_reported_unknown_count' => $providerUnknownCount,
                'provider_reported_currency_unknown_count' => $providerCurrencyUnknownCount,
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
                'unchecked_cases' => $ragUnchecked,
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
     * @param  array<int, list<int>>  $runIdsByEvaluation
     * @return array<int, array<string, mixed>>
     */
    public function humanReviewMetricsByEvaluationRunIds(int $organizationId, array $runIdsByEvaluation): array
    {
        $allRunIds = [];
        foreach ($runIdsByEvaluation as $runIds) {
            foreach ($runIds as $runId) {
                if ($runId > 0) {
                    $allRunIds[$runId] = $runId;
                }
            }
        }

        $reviews = $this->latestReviews($organizationId, array_values($allRunIds));
        $reviewsByRun = $reviews->groupBy('ai_run_id');
        $metrics = [];

        foreach ($runIdsByEvaluation as $evaluationId => $runIds) {
            $evaluationReviews = new Collection;
            foreach (array_values(array_unique($runIds)) as $runId) {
                $evaluationReviews = $evaluationReviews->merge($reviewsByRun->get($runId, new Collection));
            }

            $metrics[(int) $evaluationId] = $this->humanReviewMetrics($evaluationReviews);
        }

        return $metrics;
    }

    /**
     * @param  Collection<int, AiRunHumanReview>  $reviews
     * @return array{reviewed_cases: int, accepted_count: int, edited_and_accepted_count: int, rejected_count: int, accepted_rate: float, edited_and_accepted_rate: float, rejected_rate: float}
     */
    private function humanReviewMetrics(Collection $reviews): array
    {
        $latestReviews = new Collection;
        foreach ($reviews->groupBy('ai_run_id') as $items) {
            $review = $items->sortByDesc('review_step')->first();
            if ($review instanceof AiRunHumanReview) {
                $latestReviews->push($review);
            }
        }

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

        return $reviewCounts;
    }

    /**
     * @param  list<int>  $runIds
     * @return Collection<int, AiRunHumanReview>
     */
    private function latestReviews(int $organizationId, array $runIds): Collection
    {
        if ($runIds === []) {
            return new Collection;
        }

        return AiRunHumanReview::query()
            ->where('organization_id', $organizationId)
            ->whereIn('ai_run_id', $runIds)
            ->whereRaw(
                'review_step = (SELECT MAX(latest_reviews.review_step) FROM ai_run_human_reviews AS latest_reviews WHERE latest_reviews.organization_id = ai_run_human_reviews.organization_id AND latest_reviews.ai_run_id = ai_run_human_reviews.ai_run_id)',
            )
            ->get();
    }

    private function currency(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param  BaseCollection<int, AiRunAttempt>  $attempts
     */
    public function estimatedCurrency(AiRun $run, BaseCollection $attempts): ?string
    {
        if ($attempts->isEmpty()) {
            return null;
        }

        $currencies = [];
        foreach ($attempts as $attempt) {
            $currency = $this->currency($attempt->pricing_snapshot['currency'] ?? null);
            if ($currency === null) {
                return null;
            }

            $currencies[$currency] = true;
        }

        $embeddingPricing = $run->retrieval_embedding_pricing_snapshot;
        if (is_array($embeddingPricing) && $embeddingPricing !== []) {
            $currency = $this->currency($embeddingPricing['currency'] ?? null);
            if ($currency === null) {
                return null;
            }

            $currencies[$currency] = true;
        }

        return count($currencies) === 1 ? (string) array_key_first($currencies) : null;
    }

    private function attemptIdentity(AiRunAttempt $attempt): string
    {
        return json_encode([
            'provider' => strtolower(trim($attempt->provider)),
            'model' => strtolower(trim($attempt->model)),
            'model_release_id' => $attempt->model_release_id,
            'provider_configuration_id' => $attempt->provider_configuration_id,
            'provider_configuration_digest' => $attempt->provider_configuration_digest,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    public function columns(array $metrics): array
    {
        $estimated = $metrics['cost']['estimated_by_currency'] ?? [];
        $provider = $metrics['cost']['provider_reported_by_currency'] ?? [];

        $estimatedUnknownCount = (int) ($metrics['cost']['estimated_currency_unknown_count'] ?? 0);
        $providerUnknownCount = (int) ($metrics['cost']['provider_reported_unknown_count']
            ?? $metrics['cost']['provider_reported_currency_unknown_count']
            ?? 0);

        return [
            'estimated_cost_minor_units' => $estimatedUnknownCount === 0 && count($estimated) === 1 ? (int) array_values($estimated)[0] : null,
            'provider_cost_minor_units' => $providerUnknownCount === 0 && count($provider) === 1 ? (int) array_values($provider)[0] : null,
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
