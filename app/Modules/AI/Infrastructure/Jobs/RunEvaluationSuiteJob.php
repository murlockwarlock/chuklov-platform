<?php

namespace App\Modules\AI\Infrastructure\Jobs;

use App\Models\User;
use App\Modules\AI\Application\Actions\RunEvaluationSuite;
use App\Modules\AI\Application\Data\AiEvaluationCaseResult;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RunEvaluationSuiteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $actorId,
        public readonly int $evalSuiteId,
        public readonly int $promptVersionId,
        public readonly int $modelReleaseId,
        public readonly string $progressKey,
    ) {
        $this->onQueue('ai-evaluations');
    }

    public function handle(RunEvaluationSuite $runner, OrganizationContext $context): void
    {
        $organization = Organization::query()->whereKey($this->organizationId)->first();
        $actor = User::query()->whereKey($this->actorId)->first();
        if ($organization === null || $actor === null) {
            $this->write(['status' => 'failed', 'message' => 'Проверка не может быть запущена.']);

            return;
        }

        $context->set($organization);
        $this->write(['status' => 'running']);

        try {
            $run = $runner->handle(
                actor: $actor,
                evalSuiteId: $this->evalSuiteId,
                promptVersionId: $this->promptVersionId,
                modelReleaseId: $this->modelReleaseId,
                progress: function (AiEvaluationCaseResult $case, int $processed, int $total): void {
                    $failedCases = (array) $this->read()['failed_cases'];
                    if (! $case->passed) {
                        $failedCases[] = [
                            'case_name' => $case->caseName,
                            'test_inputs' => $case->testInputs,
                            'expected_assertions' => $case->expectedAssertions,
                            'actual_output' => $case->actualOutput,
                            'failure_explanation' => $case->failureExplanation,
                        ];
                    }
                    $this->write([
                        'status' => 'running',
                        'processed' => $processed,
                        'total' => $total,
                        'passed' => $processed - count($failedCases),
                        'failed' => count($failedCases),
                        'failed_cases' => $failedCases,
                    ]);
                },
            );
            $this->write([
                'status' => 'completed',
                'processed' => $run->total_cases,
                'total' => $run->total_cases,
                'passed' => $run->passed_cases,
                'failed' => $run->failed_cases,
                'failed_cases' => collect((array) data_get($run->results_payload, 'cases', []))
                    ->where('passed', false)
                    ->map(static fn (array $case): array => [
                        'case_name' => $case['case_name'] ?? 'Пример без названия',
                        'test_inputs' => $case['test_inputs'] ?? [],
                        'expected_assertions' => $case['expected_assertions'] ?? [],
                        'actual_output' => $case['actual_output'] ?? null,
                        'failure_explanation' => $case['failure_explanation'] ?? 'Проверка не пройдена.',
                    ])->values()->all(),
                'run_id' => $run->getKey(),
            ]);
        } catch (Throwable $exception) {
            $this->write([
                'status' => 'failed',
                'message' => $exception instanceof \InvalidArgumentException
                    ? $exception->getMessage()
                    : 'Проверка завершилась ошибкой. Проверьте настройки и повторите попытку.',
            ]);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $updates */
    private function write(array $updates): void
    {
        $current = $this->read();
        Cache::put($this->cacheKey(), [
            ...$current,
            ...$updates,
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(4));
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        return (array) Cache::get($this->cacheKey(), []);
    }

    private function cacheKey(): string
    {
        return 'ai-evaluation-progress:'.$this->progressKey;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'ai-evaluation:'.$this->evalSuiteId,
            'organization:'.$this->organizationId,
        ];
    }
}
