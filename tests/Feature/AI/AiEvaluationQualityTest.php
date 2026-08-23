<?php

namespace Tests\Feature\AI;

use App\Filament\Resources\AiEvaluations\AiEvaluationResource;
use App\Filament\Resources\AiEvaluations\RelationManagers\RunsRelationManager;
use App\Models\User;
use App\Modules\AI\Application\Actions\CompareAiEvaluationRuns;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Application\Actions\RunEvaluationSuite;
use App\Modules\AI\Application\Data\AiEvaluationCaseResult;
use App\Modules\AI\Application\Services\AiEvaluationRunMetricsAggregator;
use App\Modules\AI\Application\Services\AiEvaluationRunMetricsReader;
use App\Modules\AI\Application\Services\AiEvaluationSnapshotHasher;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiEvaluationCaseStatus;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiEvalCase;
use App\Modules\AI\Domain\Models\AiEvalRun;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\AI\Domain\Models\AiRunHumanReview;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiEvaluationQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_evaluation_run_keeps_immutable_suite_case_assertion_and_release_provenance(): void
    {
        $fixture = $this->evaluationFixture('immutable');
        $fixture['case']->update([
            'expected_output_schema' => [
                'type' => 'object',
                'properties' => ['summary' => ['type' => 'string']],
            ],
        ]);
        DynamicWorkflowAgent::fake(['{"summary":"approved"}']);

        $run = app(RunEvaluationSuite::class)->handle(
            actor: $fixture['user'],
            evalSuiteId: $fixture['suite']->getKey(),
            promptVersionId: $fixture['version']->getKey(),
            modelReleaseId: $fixture['release']->getKey(),
        );

        $snapshot = $run->provenance_snapshot;
        self::assertIsArray($snapshot);
        self::assertSame('Quality suite', $snapshot['suite']['name']);
        self::assertSame('Original case', $snapshot['cases'][0]['name']);
        self::assertSame('required_text', $snapshot['cases'][0]['assertions'][0]['type']);
        self::assertSame('object', $snapshot['cases'][0]['expected_output_schema']['type']);
        self::assertSame(
            app(AiEvaluationSnapshotHasher::class)->testInputsDigest(['query' => 'synthetic fixture']),
            $snapshot['cases'][0]['test_inputs_digest'],
        );
        self::assertSame($fixture['version']->getKey(), $snapshot['prompt_version']['id']);
        self::assertSame($fixture['release']->getKey(), $snapshot['model_release']['id']);
        self::assertSame('openai', $run->results_payload['cases'][0]['actual_provider']);
        self::assertTrue(AuditEvent::query()
            ->where('organization_id', $fixture['organization']->getKey())
            ->where('action', 'ai.evaluation_run.completed')
            ->where('target_id', (string) $run->getKey())
            ->exists());

        $fixture['suite']->update(['name' => 'Changed suite']);
        $fixture['case']->update([
            'name' => 'Changed case',
            'test_inputs' => ['query' => 'changed input'],
            'expected_assertions' => [['type' => 'forbidden_text', 'value' => 'changed']],
            'expected_output_schema' => ['type' => 'array'],
        ]);
        $run->refresh();

        $snapshot = $run->provenance_snapshot;
        self::assertIsArray($snapshot);
        self::assertSame('Quality suite', $snapshot['suite']['name']);
        self::assertSame('Original case', $snapshot['cases'][0]['name']);
        self::assertSame('required_text', $snapshot['cases'][0]['assertions'][0]['type']);
        self::assertSame('object', $snapshot['cases'][0]['expected_output_schema']['type']);
        self::assertSame(
            app(AiEvaluationSnapshotHasher::class)->testInputsDigest(['query' => 'synthetic fixture']),
            $snapshot['cases'][0]['test_inputs_digest'],
        );
        self::assertSame('Original case', $run->results_payload['cases'][0]['case_name']);
    }

    public function test_metrics_keep_costs_distinct_and_aggregate_latency_retries_failover_and_reviews(): void
    {
        $fixture = $this->evaluationFixture('metrics');
        $decisions = [
            HumanReviewDecision::Accepted,
            HumanReviewDecision::EditedAndAccepted,
            HumanReviewDecision::Rejected,
        ];
        $runIds = [];
        $caseResults = [];

        foreach ($decisions as $index => $decision) {
            $aiRun = $this->observedRun($fixture, $index + 1, $decision);
            $runIds[] = $aiRun->getKey();
            $caseResults[] = new AiEvaluationCaseResult(
                caseId: $index + 1,
                caseName: 'Пример '.($index + 1),
                aiRunId: $aiRun->getKey(),
                status: AiEvaluationCaseStatus::Passed,
                passed: true,
                failureCategory: null,
                failureCode: null,
                failureExplanation: 'Все проверки выполнены.',
            );
        }

        $runs = AiRun::query()->whereIn('id', $runIds)->get();
        AiRunAttempt::query()
            ->where('ai_run_id', $runIds[2])
            ->where('attempt_number', 2)
            ->update(['provider' => 'anthropic', 'model' => 'claude-sonnet']);
        $runs = AiRun::query()->whereIn('id', $runIds)->get();

        $metrics = app(AiEvaluationRunMetricsAggregator::class)->aggregate(
            organizationId: $fixture['organization']->getKey(),
            runs: $runs,
            caseResults: $caseResults,
        );

        self::assertSame(['USD' => 360], $metrics['cost']['estimated_by_currency']);
        self::assertSame(['USD' => 150], $metrics['cost']['provider_reported_by_currency']);
        self::assertNotSame($metrics['cost']['estimated_by_currency'], $metrics['cost']['provider_reported_by_currency']);
        self::assertSame(2, $metrics['reliability']['retry_count']);
        self::assertSame(1, $metrics['reliability']['failover_count']);
        self::assertSame(1200, $metrics['latency']['total_ms']);
        self::assertSame(3, $metrics['human_review']['reviewed_cases']);
        self::assertSame(1, $metrics['human_review']['accepted_count']);
        self::assertSame(1, $metrics['human_review']['edited_and_accepted_count']);
        self::assertSame(1, $metrics['human_review']['rejected_count']);

        $firstRun = $runs->first();
        self::assertInstanceOf(AiRun::class, $firstRun);

        $uncheckedRag = app(AiEvaluationRunMetricsAggregator::class)->aggregate(
            $fixture['organization']->getKey(),
            new Collection([$firstRun]),
            [new AiEvaluationCaseResult(
                caseId: 99,
                caseName: 'Execution failure',
                aiRunId: $firstRun->getKey(),
                status: AiEvaluationCaseStatus::ExecutionFailed,
                passed: false,
                failureCategory: 'execution',
                failureCode: 'provider_failed',
                failureExplanation: 'Provider failed.',
                rag: ['checks_present' => true],
            )],
        );
        self::assertSame(0, $uncheckedRag['rag']['checked_cases']);
        self::assertSame(1, $uncheckedRag['rag']['unchecked_cases']);
    }

    public function test_comparison_requires_compatible_snapshots_and_rejects_other_organization(): void
    {
        $local = $this->evaluationFixture('comparison_local');
        $runOne = $this->evaluationRunRecord($local, 80.0, 'first');
        $runTwo = $this->evaluationRunRecord($local, 90.0, 'second');

        $comparison = app(CompareAiEvaluationRuns::class)->handle($local['user'], [$runOne->getKey(), $runTwo->getKey()]);

        self::assertTrue($comparison->compatible);
        self::assertCount(2, $comparison->runs);
        self::assertStringContainsString('Расчётная стоимость Chuklov', $comparison->toRussianSummary());
        self::assertStringContainsString('Стоимость от провайдера', $comparison->toRussianSummary());
        self::assertStringContainsString('Токены', $comparison->toRussianSummary());

        $originalSnapshot = $runTwo->provenance_snapshot;
        $inputChangedSnapshot = $runTwo->provenance_snapshot;
        $inputChangedSnapshot['cases'][0]['test_inputs_digest'] = app(AiEvaluationSnapshotHasher::class)
            ->testInputsDigest(['query' => 'different synthetic input']);
        $runTwo->update(['provenance_snapshot' => $inputChangedSnapshot]);
        $inputIncompatible = app(CompareAiEvaluationRuns::class)->handle($local['user'], [$runOne->getKey(), $runTwo->getKey()]);
        self::assertFalse($inputIncompatible->compatible);
        self::assertStringContainsString('Наборы примеров отличаются', $inputIncompatible->message);

        $runTwo->update(['provenance_snapshot' => $originalSnapshot]);
        $changedSnapshot = $runTwo->provenance_snapshot;
        $changedSnapshot['cases'][0]['assertions'] = [['type' => 'forbidden_text', 'value' => 'changed']];
        $runTwo->update(['provenance_snapshot' => $changedSnapshot]);
        $incompatible = app(CompareAiEvaluationRuns::class)->handle($local['user'], [$runOne->getKey(), $runTwo->getKey()]);
        self::assertFalse($incompatible->compatible);
        self::assertStringContainsString('Наборы примеров отличаются', $incompatible->message);

        $schemaChangedSnapshot = $runTwo->provenance_snapshot;
        $schemaChangedSnapshot['cases'][0]['expected_output_schema'] = ['type' => 'object'];
        $runTwo->update(['provenance_snapshot' => $schemaChangedSnapshot]);
        $schemaIncompatible = app(CompareAiEvaluationRuns::class)->handle($local['user'], [$runOne->getKey(), $runTwo->getKey()]);
        self::assertFalse($schemaIncompatible->compatible);
        self::assertStringContainsString('Наборы примеров отличаются', $schemaIncompatible->message);

        $foreign = $this->evaluationFixture('comparison_foreign');
        $foreignRun = $this->evaluationRunRecord($foreign, 70.0, 'foreign');
        app(OrganizationContext::class)->set($local['organization']);
        config()->set('tenancy.default_organization_id', $local['organization']->getKey());

        $this->expectException(AuthorizationException::class);
        app(CompareAiEvaluationRuns::class)->handle($local['user'], [$runOne->getKey(), $foreignRun->getKey()]);
    }

    public function test_legacy_evaluation_without_observability_snapshots_degrades_without_recomputation(): void
    {
        $fixture = $this->evaluationFixture('legacy_snapshot');
        $legacyRun = AiEvalRun::create([
            'organization_id' => $fixture['organization']->getKey(),
            'eval_suite_id' => $fixture['suite']->getKey(),
            'prompt_version_id' => $fixture['version']->getKey(),
            'model_release_id' => $fixture['release']->getKey(),
            'total_cases' => 1,
            'passed_cases' => 1,
            'failed_cases' => 0,
            'pass_percentage' => 100.0,
            'results_payload' => ['cases' => [['passed' => true]]],
            'metrics_payload' => null,
            'provenance_snapshot' => null,
        ]);

        self::assertSame([], app(AiEvaluationRunMetricsReader::class)->forRun($legacyRun));

        $currentRun = $this->evaluationRunRecord($fixture, 90.0, 'current');
        $comparison = app(CompareAiEvaluationRuns::class)->handle(
            $fixture['user'],
            [$legacyRun->getKey(), $currentRun->getKey()],
        );

        self::assertFalse($comparison->compatible);
        self::assertStringContainsString('нет полного снимка', $comparison->message);
    }

    public function test_human_review_metrics_follow_later_and_latest_review_decisions(): void
    {
        $fixture = $this->evaluationFixture('human_review_lifecycle');
        $aiRun = $this->observedRun($fixture, 1, null);
        $evaluationRun = $this->evaluationRunRecord($fixture, 100.0, 'human review');
        $resultsPayload = $evaluationRun->results_payload;
        $resultsPayload['cases'][0]['ai_run_id'] = $aiRun->getKey();
        $evaluationRun->update(['results_payload' => $resultsPayload]);

        $reader = app(AiEvaluationRunMetricsReader::class);
        self::assertSame(0, $reader->forRun($evaluationRun)['human_review']['reviewed_cases']);

        app(ReviewAiRun::class)->handle(
            actor: $fixture['user'],
            runId: $aiRun->getKey(),
            decision: HumanReviewDecision::Accepted,
        );
        $accepted = $reader->forRun($evaluationRun)['human_review'];
        self::assertSame(1, $accepted['reviewed_cases']);
        self::assertSame(1, $accepted['accepted_count']);

        app(ReviewAiRun::class)->handle(
            actor: $fixture['user'],
            runId: $aiRun->getKey(),
            decision: HumanReviewDecision::Rejected,
        );
        $latest = $reader->forRun($evaluationRun)['human_review'];
        self::assertSame(1, $latest['reviewed_cases']);
        self::assertSame(0, $latest['accepted_count']);
        self::assertSame(1, $latest['rejected_count']);

        $comparisonRun = $this->evaluationRunRecord($fixture, 90.0, 'comparison');
        $comparison = app(CompareAiEvaluationRuns::class)->handle(
            $fixture['user'],
            [$evaluationRun->getKey(), $comparisonRun->getKey()],
        );
        self::assertTrue($comparison->compatible);
        self::assertSame(1, $comparison->runs[0]['human_review']['rejected_count']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $reader->forRuns(new Collection([$evaluationRun, $comparisonRun]));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        self::assertLessThanOrEqual(2, $queryCount);
    }

    public function test_costs_keep_currency_and_unknown_provider_costs_explicit(): void
    {
        $fixture = $this->evaluationFixture('currency');
        $runUsd = $this->observedRun($fixture, 1, null);
        $runEur = $this->observedRun($fixture, 2, null);
        AiRunAttempt::query()
            ->where('ai_run_id', $runEur->getKey())
            ->update(['pricing_snapshot' => ['currency' => 'EUR']]);

        $caseResults = [
            new AiEvaluationCaseResult(1, 'USD', $runUsd->getKey(), AiEvaluationCaseStatus::Passed, true, null, null, 'ok'),
            new AiEvaluationCaseResult(2, 'EUR', $runEur->getKey(), AiEvaluationCaseStatus::Passed, true, null, null, 'ok'),
        ];
        $metrics = app(AiEvaluationRunMetricsAggregator::class)->aggregate(
            $fixture['organization']->getKey(),
            new Collection([$runUsd, $runEur]),
            $caseResults,
        );

        self::assertSame(['USD' => 120, 'EUR' => 120], $metrics['cost']['estimated_by_currency']);
        self::assertSame(['USD' => 50, 'EUR' => 50], $metrics['cost']['provider_reported_by_currency']);
        self::assertNull(app(AiEvaluationRunMetricsAggregator::class)->columns($metrics)['provider_cost_minor_units']);

        AiRunAttempt::query()->where('ai_run_id', $runUsd->getKey())->update([
            'provider_cost_minor_units' => null,
            'pricing_snapshot' => [],
        ]);
        $unknownMetrics = app(AiEvaluationRunMetricsAggregator::class)->aggregate(
            $fixture['organization']->getKey(),
            new Collection([$runUsd]),
            [$caseResults[0]],
        );
        self::assertSame(2, $unknownMetrics['cost']['provider_reported_unknown_count']);
        self::assertSame(0, $unknownMetrics['cost']['provider_reported_currency_unknown_count']);
        self::assertSame([], $unknownMetrics['cost']['provider_reported_by_currency']);
        self::assertSame(1, $unknownMetrics['cost']['estimated_currency_unknown_count']);
        self::assertSame([], $unknownMetrics['cost']['estimated_by_currency']);
        self::assertNull(app(AiEvaluationRunMetricsAggregator::class)->columns($unknownMetrics)['estimated_cost_minor_units']);
        self::assertNull(app(AiEvaluationRunMetricsAggregator::class)->columns($unknownMetrics)['provider_cost_minor_units']);

        AiRunAttempt::query()->where('ai_run_id', $runUsd->getKey())->where('attempt_number', 2)->update([
            'provider_cost_minor_units' => 30,
            'pricing_snapshot' => [],
        ]);
        $missingCurrencyMetrics = app(AiEvaluationRunMetricsAggregator::class)->aggregate(
            $fixture['organization']->getKey(),
            new Collection([$runUsd]),
            [$caseResults[0]],
        );
        self::assertSame(2, $missingCurrencyMetrics['cost']['provider_reported_unknown_count']);
        self::assertSame(1, $missingCurrencyMetrics['cost']['provider_reported_currency_unknown_count']);
        self::assertNull(app(AiEvaluationRunMetricsAggregator::class)->columns($missingCurrencyMetrics)['provider_cost_minor_units']);
    }

    public function test_filament_exposes_russian_quality_history_under_ai_administration(): void
    {
        self::assertSame('Проверки AI', AiEvaluationResource::getNavigationLabel());
        self::assertSame('Искусственный интеллект', AiEvaluationResource::getNavigationGroup());
        self::assertContains(RunsRelationManager::class, AiEvaluationResource::getRelations());
    }

    public function test_history_uses_immutable_prompt_and_model_labels(): void
    {
        $fixture = $this->evaluationFixture('history_labels');
        $run = $this->evaluationRunRecord($fixture, 90.0, 'history labels');

        $fixture['version']->update(['version' => 99]);
        $fixture['release']->update([
            'provider_name' => 'changed-provider',
            'model_name' => 'changed-model',
            'release_number' => 99,
        ]);

        $reflection = new \ReflectionClass(RunsRelationManager::class);
        $promptLabel = $reflection->getMethod('promptLabel');
        $modelLabel = $reflection->getMethod('modelLabel');
        $promptLabel->setAccessible(true);
        $modelLabel->setAccessible(true);

        self::assertSame('v1', $promptLabel->invoke(null, $run));
        self::assertSame('openai · gpt-4o-mini · выпуск 1', $modelLabel->invoke(null, $run));
    }

    public function test_snapshot_hash_is_stable_for_irrelevant_case_and_definition_order(): void
    {
        $hasher = app(AiEvaluationSnapshotHasher::class);
        $first = [
            [
                'id' => 2,
                'assertions' => [
                    ['type' => 'forbidden_text', 'value' => 'unsafe'],
                    ['type' => 'required_text', 'value' => 'safe'],
                ],
                'expected_output_schema' => [
                    'type' => 'object',
                    'required' => ['a', 'b'],
                    'properties' => ['b' => ['type' => 'string'], 'a' => ['type' => 'string']],
                ],
                'test_inputs_digest' => $hasher->testInputsDigest(['query' => 'two']),
            ],
            [
                'id' => 1,
                'assertions' => [],
                'expected_output_schema' => null,
                'test_inputs_digest' => $hasher->testInputsDigest(['query' => 'one']),
            ],
        ];
        $second = [
            [
                'id' => 1,
                'assertions' => [],
                'expected_output_schema' => null,
                'test_inputs_digest' => $hasher->testInputsDigest(['query' => 'one']),
            ],
            [
                'id' => 2,
                'assertions' => [
                    ['value' => 'safe', 'type' => 'required_text'],
                    ['value' => 'unsafe', 'type' => 'forbidden_text'],
                ],
                'expected_output_schema' => [
                    'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']],
                    'required' => ['b', 'a'],
                    'type' => 'object',
                ],
                'test_inputs_digest' => $hasher->testInputsDigest(['query' => 'two']),
            ],
        ];

        self::assertSame($hasher->casesDigest($first), $hasher->casesDigest($second));
        self::assertSame(
            $hasher->testInputsDigest(['second' => 2, 'first' => 1]),
            $hasher->testInputsDigest(['first' => 1, 'second' => 2]),
        );
    }

    /** @return array{organization: Organization, user: User, model: AiModelConfiguration, prompt: AiPrompt, version: AiPromptVersion, suite: AiEvalSuite, case: AiEvalCase, release: AiModelRelease} */
    private function evaluationFixture(string $key): array
    {
        $organization = Organization::create([
            'name' => 'Quality '.$key,
            'slug' => 'quality-'.$key,
        ]);
        $user = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'Quality '.$key,
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $organization->getKey();
        $credential->credentials = ['api_key' => 'sk-test'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'is_enabled' => true,
            'health_status' => ProviderHealthStatus::Healthy,
            'credential_id' => $credential->getKey(),
            'tested_credential_revision' => $credential->revision_id,
            'tested_configuration_digest' => AiProviderExecutionConfiguration::digest('openai'),
        ]);
        $pricing = new AiPricingSnapshot(currency: 'USD', inputCostPerMillionMinorUnits: 15, outputCostPerMillionMinorUnits: 60);
        $model = AiModelConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_config_id' => $provider->getKey(),
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'is_enabled' => true,
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => 1,
        ]);
        $release = AiModelRelease::create([
            'organization_id' => $organization->getKey(),
            'model_config_id' => $model->getKey(),
            'release_number' => 1,
            'status' => 'active',
            'provider_name' => 'openai',
            'model_name' => 'gpt-4o-mini',
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => $pricing->toArray(),
            'activated_at' => Carbon::now(),
        ]);
        $model->update(['active_release_id' => $release->getKey()]);

        $prompt = AiPrompt::create([
            'organization_id' => $organization->getKey(),
            'key' => 'quality_prompt_'.$key,
            'name' => 'Quality prompt '.$key,
            'capability' => AiCapability::ClientCompanion,
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $organization->getKey(),
            'prompt_id' => $prompt->getKey(),
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Проверьте синтетический ответ.',
            'user_prompt_template' => '{{query}}',
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->getKey()]);
        $suite = AiEvalSuite::create([
            'organization_id' => $organization->getKey(),
            'key' => 'quality_suite_'.$key,
            'name' => 'Quality suite',
            'capability' => AiCapability::ClientCompanion,
            'prompt_id' => $prompt->getKey(),
        ]);
        $case = AiEvalCase::create([
            'organization_id' => $organization->getKey(),
            'eval_suite_id' => $suite->getKey(),
            'name' => 'Original case',
            'is_synthetic' => true,
            'is_deidentified' => false,
            'test_inputs' => ['query' => 'synthetic fixture'],
            'expected_assertions' => [['type' => 'required_text', 'value' => 'approved']],
            'is_active' => true,
        ]);

        return compact('organization', 'user', 'model', 'prompt', 'version', 'suite', 'case', 'release');
    }

    /** @param array{organization: Organization, version: AiPromptVersion, suite: AiEvalSuite, release: AiModelRelease} $fixture */
    private function observedRun(array $fixture, int $number, ?HumanReviewDecision $decision): AiRun
    {
        $run = AiRun::create([
            'organization_id' => $fixture['organization']->getKey(),
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'quality_metric_'.$number,
            'origin' => AiRunOrigin::Evaluation,
            'status' => AiRunStatus::Succeeded,
            'execution_mode' => AiExecutionMode::Evaluation,
            'prompt_version_id' => $fixture['version']->getKey(),
            'model_release_id' => $fixture['release']->getKey(),
            'actual_provider' => 'openai',
            'actual_model' => 'gpt-4o-mini',
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
            'settled_estimated_cost_minor_units' => 120,
            'cost_currency' => 'USD',
            'latency_ms' => 400,
            'attempt_count' => 2,
        ]);
        $common = [
            'organization_id' => $fixture['organization']->getKey(),
            'ai_run_id' => $run->getKey(),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'status' => 'failed',
            'latency_ms' => 100,
            'token_usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10],
            'reserved_cost_minor_units' => 20,
            'budget_usage_date' => Carbon::now()->toDateString(),
            'budget_reservation_status' => 'settled',
            'settled_estimated_cost_minor_units' => 40,
            'provider_cost_minor_units' => 20,
            'pricing_snapshot' => ['currency' => 'USD'],
            'retry_or_failover_reason' => 'provider_retry',
        ];
        AiRunAttempt::create([...$common, 'attempt_number' => 1]);
        AiRunAttempt::create([
            ...$common,
            'attempt_number' => 2,
            'status' => 'succeeded',
            'latency_ms' => 300,
            'provider_cost_minor_units' => 30,
            'retry_or_failover_reason' => 'provider_failover',
        ]);
        if ($decision instanceof HumanReviewDecision) {
            AiRunHumanReview::create([
                'organization_id' => $fixture['organization']->getKey(),
                'ai_run_id' => $run->getKey(),
                'review_step' => 1,
                'decision' => $decision,
                'safe_reason_code' => 'quality_test',
                'reviewed_at' => Carbon::now(),
            ]);
        }

        return $run;
    }

    /** @param array{organization: Organization, user: User, suite: AiEvalSuite, case: AiEvalCase, version: AiPromptVersion, release: AiModelRelease} $fixture */
    private function evaluationRunRecord(array $fixture, float $passPercentage, string $label): AiEvalRun
    {
        return AiEvalRun::create([
            'organization_id' => $fixture['organization']->getKey(),
            'eval_suite_id' => $fixture['suite']->getKey(),
            'prompt_version_id' => $fixture['version']->getKey(),
            'model_release_id' => $fixture['release']->getKey(),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'total_cases' => 1,
            'passed_cases' => $passPercentage >= 50 ? 1 : 0,
            'failed_cases' => $passPercentage >= 50 ? 0 : 1,
            'pass_percentage' => $passPercentage,
            'average_latency_ms' => 400,
            'estimated_cost_minor_units' => 120,
            'provider_cost_minor_units' => 50,
            'results_payload' => ['cases' => [['case_name' => $label, 'passed' => $passPercentage >= 50]]],
            'metrics_payload' => [
                'case_breakdown' => ['passed' => 1, 'assertion' => 0],
                'rag' => ['checked_cases' => 0],
                'tokens' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
                'human_review' => ['accepted_count' => 0, 'edited_and_accepted_count' => 0, 'rejected_count' => 0],
                'cost' => [
                    'estimated_by_currency' => ['USD' => 120],
                    'provider_reported_by_currency' => ['USD' => 50],
                ],
            ],
            'provenance_snapshot' => [
                'schema_version' => 2,
                'suite' => [
                    'id' => $fixture['suite']->getKey(),
                    'capability' => AiCapability::ClientCompanion->value,
                ],
                'capability' => AiCapability::ClientCompanion->value,
                'prompt_version' => ['id' => $fixture['version']->getKey(), 'version' => 1],
                'model_release' => [
                    'id' => $fixture['release']->getKey(),
                    'provider' => 'openai',
                    'model' => 'gpt-4o-mini',
                    'release_number' => 1,
                ],
                'cases' => [[
                    'id' => $fixture['case']->getKey(),
                    'name' => 'Original case',
                    'assertions' => [['type' => 'required_text', 'value' => 'approved']],
                    'expected_output_schema' => null,
                    'test_inputs_digest' => app(AiEvaluationSnapshotHasher::class)->testInputsDigest($fixture['case']->test_inputs),
                ]],
            ],
        ]);
    }
}
