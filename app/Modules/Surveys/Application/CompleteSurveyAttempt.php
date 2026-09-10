<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyComparison;
use App\Modules\Surveys\Domain\Models\SurveyReport;
use App\Modules\Surveys\Domain\Services\SurveyScorer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class CompleteSurveyAttempt
{
    public function __construct(
        private SurveyAuthorization $authorization,
        private SurveyScorer $scorer,
        private RecordScenarioEvent $events,
        private RecordAuditEvent $audit,
        private CompareSurveyAttempts $compare,
        private SurveyReportBuilder $reportBuilder,
    ) {}

    /** @param array<string, mixed> $answers */
    public function handle(Client $client, SurveyAttempt $attempt, array $answers): SurveyAttempt
    {
        $this->authorization->assertAttemptOwner($client, $attempt);

        $completed = DB::transaction(function () use ($client, $attempt, $answers): SurveyAttempt {
            $locked = SurveyAttempt::query()->where('organization_id', $client->organization_id)->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === SurveyAttemptStatus::Completed) {
                $comparison = $this->compare->handle($locked);
                $this->refreshReport($locked, $comparison);

                return $locked;
            }
            $merged = [...($locked->answers_snapshot ?? []), ...$answers];
            $result = $this->scorer->complete($locked->definition_snapshot, $locked->scoring_snapshot, $merged);
            $completedAt = CarbonImmutable::now();
            $locked->forceFill([
                'status' => SurveyAttemptStatus::Completed,
                'answers_snapshot' => $merged,
                'result_snapshot' => $result,
                'completed_at' => $completedAt,
            ])->save();
            $definition = $locked->surveyDefinition()->firstOrFail();
            $version = $locked->surveyVersion()->firstOrFail();
            $report = SurveyReport::query()->firstOrCreate(
                ['organization_id' => $locked->organization_id, 'survey_attempt_id' => $locked->getKey()],
                [
                    'client_id' => $locked->client_id,
                    'survey_version_id' => $locked->survey_version_id,
                    'title' => $version->title,
                    'report_snapshot' => [
                        'survey' => [
                            'definition_key' => $definition->definition_key,
                            'version' => $version->version,
                            'title' => array_filter([
                                'ru' => $version->title,
                                'en' => $version->title_en,
                            ], static fn (?string $value): bool => $value !== null && $value !== ''),
                        ],
                        'completed_at' => $completedAt->toIso8601String(),
                        'metrics' => $result['metrics'],
                        'thresholds' => $result['thresholds'],
                        'tags' => $result['tags'],
                        'source' => $version->source,
                        'approval_status' => $version->approval_status,
                    ],
                    'materialized_at' => $completedAt,
                ],
            );
            $this->events->surveyCompleted($locked, $report, $completedAt);
            $this->audit->handle(
                $definition->organization()->firstOrFail(),
                null,
                'survey.attempt.completed',
                SurveyAttempt::class,
                (string) $locked->getKey(),
                [
                    'definition_key' => $definition->definition_key,
                    'version' => $version->version,
                    'tag_count' => count($result['tags']),
                    'metric_count' => count($result['metrics']),
                ],
            );
            $comparison = $this->compare->handle($locked);
            $report->forceFill([
                'report_snapshot' => $this->reportBuilder->handle($definition, $version, $locked, $result, $comparison),
                'materialized_at' => $completedAt,
            ])->save();

            return $locked->refresh();
        });

        return $completed->refresh();
    }

    private function refreshReport(SurveyAttempt $attempt, ?SurveyComparison $comparison): void
    {
        $report = $attempt->report()->first();
        if ($report === null) {
            return;
        }

        $definition = $attempt->surveyDefinition()->first();
        $version = $attempt->surveyVersion()->first();
        if ($definition === null || $version === null || $attempt->result_snapshot === null) {
            return;
        }

        $report->forceFill([
            'report_snapshot' => $this->reportBuilder->handle($definition, $version, $attempt, $attempt->result_snapshot, $comparison),
            'materialized_at' => now(),
        ])->save();
    }
}
