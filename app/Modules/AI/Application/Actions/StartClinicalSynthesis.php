<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\MedicalSessionAuthorization;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Surveys\Application\SurveyAuthorization;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use Illuminate\Support\Str;

final readonly class StartClinicalSynthesis
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private GetClinicalAiResult $resultReader,
        private GetMedicalProfile $getMedicalProfile,
        private MedicalSessionAuthorization $sessionAuthorization,
        private GetSession $getSession,
        private SurveyAuthorization $surveyAuthorization,
        private DispatchAsyncAiRun $dispatcher,
    ) {}

    public function handle(User $actor, Client $client, bool $rerun = false): AiRun
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);
        $this->sessionAuthorization->authorizeViewClient($actor, $client);

        $documentRun = $this->latestReviewedRun($client, AiCapability::ClinicalDocumentExtraction);
        $postureRun = $this->latestReviewedRun($client, AiCapability::PostureAnalysis);
        $documentResult = $documentRun === null ? null : $this->resultReader->handle($actor, $documentRun->id, $client->id);
        $postureResult = $postureRun === null ? null : $this->resultReader->handle($actor, $postureRun->id, $client->id);
        $profile = $this->getMedicalProfile->handle($actor, $client);

        $sessionHistory = [];
        $sessionReferences = [];
        $sessions = MedicalSession::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();
        foreach ($sessions as $session) {
            $sessionData = $this->getSession->handle($actor, $session, $client);
            if ($sessionData === null) {
                continue;
            }

            $sessionHistory[] = $sessionData->toArray();
            $sessionReferences[] = new AiInputReference('medical_session', (int) $session->getKey());
        }

        [$surveyResults, $surveyReferences] = $this->surveyBundle($actor, $client);
        $inputVariables = [
            'client_name' => (string) ($client->full_name ?: 'Клиент'),
            'anamnesis' => $this->boundedText($profile->anamnesis ?? '', 400),
            'complaints_goals' => $this->boundedText($profile->complaintsGoals ?? '', 700),
            'recent_sessions' => array_map(
                fn (array $session): array => $this->sessionContext($session),
                $sessionHistory,
            ),
            'agent_one_result' => $this->documentContext($documentResult?->outputPayload),
            'agent_two_result' => $this->postureContext($postureResult?->outputPayload),
            'survey_results' => $surveyResults,
        ];

        $references = [new AiInputReference('client', (int) $client->getKey())];
        if ($documentRun !== null) {
            $references[] = new AiInputReference('ai_run', (int) $documentRun->getKey());
        }
        if ($postureRun !== null) {
            $references[] = new AiInputReference('ai_run', (int) $postureRun->getKey());
        }
        $references = [...$references, ...$sessionReferences, ...$surveyReferences];

        $sourceDigest = hash('sha256', json_encode([
            'variables' => $inputVariables,
            'references' => array_map(static fn (AiInputReference $reference): array => $reference->toArray(), $references),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $baseKey = 'clinical_synthesizer:'.$sourceDigest;
        $idempotencyKey = $this->idempotencyKey(
            organizationId: (int) $organization->getKey(),
            baseKey: $baseKey,
            rerun: $rerun,
        );

        return $this->dispatcher->handle($actor, new AiRunRequest(
            capability: AiCapability::ClinicalSynthesizer,
            workflowKey: 'clinical_synthesizer',
            origin: AiRunOrigin::User,
            executionMode: AiExecutionMode::Async,
            clientId: (int) $client->getKey(),
            inputVariables: $inputVariables,
            inputReferences: $references,
            idempotencyKey: $idempotencyKey,
            actor: $actor,
        ));
    }

    private function latestReviewedRun(Client $client, AiCapability $capability): ?AiRun
    {
        return AiRun::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->where('capability', $capability)
            ->where('status', AiRunStatus::Succeeded)
            ->whereIn('human_review_status', [
                HumanReviewStatus::Accepted,
                HumanReviewStatus::EditedAndAccepted,
            ])
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
    }

    private function idempotencyKey(int $organizationId, string $baseKey, bool $rerun): string
    {
        if ($rerun) {
            return $baseKey.':rerun:'.substr(hash('sha256', (string) Str::uuid()), 0, 16);
        }

        $failedRun = AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('idempotency_key', $baseKey)
            ->whereIn('status', ['failed', 'timed_out', 'invalid_output', 'cancelled'])
            ->first();

        return $failedRun === null ? $baseKey : $baseKey.':retry:'.$failedRun->getKey();
    }

    /** @return array{0: array<string, mixed>, 1: list<AiInputReference>} */
    private function surveyBundle(User $actor, Client $client): array
    {
        if (! $this->surveyAuthorization->allowsView($actor, $client)) {
            return [[
                'status' => 'missing',
                'reason' => 'Результаты опросов недоступны для текущего специалиста.',
            ], []];
        }

        $attempts = SurveyAttempt::query()
            ->where('organization_id', $client->organization_id)
            ->where('client_id', $client->getKey())
            ->where('status', SurveyAttemptStatus::Completed)
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        if ($attempts->isEmpty()) {
            return [[
                'status' => 'missing',
                'reason' => 'Совместимый завершённый результат опроса отсутствует. Источник 9 систем/MSQ не предоставлен.',
            ], []];
        }

        $references = [];
        $results = [];
        foreach ($attempts as $index => $attempt) {
            $references[] = new AiInputReference('survey_attempt', (int) $attempt->getKey());
            $results[] = [
                'attempt_id' => (int) $attempt->getKey(),
                'completed_at' => $attempt->completed_at?->toIso8601String(),
                'result' => $this->surveyContext($attempt->result_snapshot, $index === 0),
            ];
        }

        return [[
            'status' => 'available',
            'attempts' => $results,
            'missing_authoritative_sources' => [
                'Источник 9 систем/MSQ и его оценивание не предоставлен в авторитетных материалах.',
            ],
        ], $references];
    }

    /** @param array<string, mixed>|null $result */
    private function documentContext(?array $result): array
    {
        if ($result === null) {
            return [
                'status' => 'missing',
                'reason' => 'Проверенный анализ медицинского документа отсутствует.',
            ];
        }

        $findings = [];
        foreach (array_slice(array_values(array_filter($result['key_findings'] ?? [], 'is_array')), 0, 3) as $finding) {
            $findings[] = [
                'location' => $this->boundedText($finding['location'] ?? '', 80),
                'pathology' => $this->boundedText($finding['pathology'] ?? '', 150),
                'size_mm' => is_scalar($finding['size_mm'] ?? null) ? $finding['size_mm'] : null,
                'impact' => $this->boundedText($finding['impact'] ?? '', 100),
            ];
        }

        return [
            'status' => 'available',
            'exam_type' => $this->boundedText($result['exam_type'] ?? '', 120),
            'anatomical_region' => $this->boundedText($result['anatomical_region'] ?? '', 120),
            'key_findings' => $findings,
            'structural_deformations' => $this->textList($result['structural_deformations'] ?? [], 2, 100),
            'critical_flags' => $this->textList($result['critical_flags'] ?? [], 2, 100),
            'plain_summary' => $this->boundedText($result['plain_summary'] ?? '', 260),
        ];
    }

    /** @param array<string, mixed>|null $result */
    private function postureContext(?array $result): array
    {
        if ($result === null) {
            return [
                'status' => 'missing',
                'reason' => 'Проверенный анализ осанки отсутствует.',
            ];
        }

        $findings = [];
        foreach (array_slice(array_values(array_filter($result['visual_findings'] ?? [], 'is_array')), 0, 3) as $finding) {
            $findings[] = [
                'plane' => $this->boundedText($finding['plane'] ?? '', 30),
                'observations' => $this->textList($finding['observations'] ?? [], 1, 110),
            ];
        }

        return [
            'status' => 'available',
            'visual_findings' => $findings,
            'leading_compensatory_patterns' => $this->textList($result['leading_compensatory_patterns'] ?? [], 2, 110),
            'practitioner_focus' => $this->textList($result['practitioner_focus'] ?? [], 2, 110),
            'limitations' => $this->textList($result['limitations'] ?? [], 2, 110),
        ];
    }

    /** @param array<string, mixed> $session */
    private function sessionContext(array $session): array
    {
        return [
            'id' => (int) ($session['id'] ?? 0),
            'occurred_at' => $this->boundedText($session['occurred_at'] ?? '', 40),
            'pain' => $this->boundedText($session['pain'] ?? '', 90),
            'tests' => $this->boundedText($session['tests'] ?? '', 70),
            'observations' => $this->boundedText($session['observations'] ?? '', 100),
            'root_cause_hypothesis' => $this->boundedText($session['root_cause_hypothesis'] ?? '', 80),
            'protocol' => $this->boundedText($session['protocol'] ?? '', 80),
            'result' => $this->boundedText($session['result'] ?? '', 100),
        ];
    }

    /** @param array<string, mixed> $result */
    private function surveyContext(array $result, bool $includeAttentionAreas): array
    {
        $metrics = [];
        foreach ((array) ($result['metrics'] ?? []) as $key => $metric) {
            if (! is_array($metric)) {
                continue;
            }
            $metrics[(string) $key] = [
                'score' => is_numeric($metric['normalized_score'] ?? null) ? (int) $metric['normalized_score'] : null,
                'raw_score' => is_numeric($metric['value'] ?? null) ? (float) $metric['value'] : null,
            ];
        }

        $attentionAreas = [];
        if ($includeAttentionAreas) {
            foreach (array_slice(array_values(array_filter($result['attention_areas'] ?? [], 'is_array')), 0, 3) as $area) {
                $attentionAreas[] = [
                    'label' => $this->localizedText($area['label'] ?? '', 80),
                    'score' => is_numeric($area['score'] ?? null) ? (int) $area['score'] : null,
                    'status' => $this->localizedText($area['status'] ?? '', 70),
                    'reason' => $this->localizedText($area['reason'] ?? '', 120),
                ];
            }
        }

        $comparison = null;
        if (is_array($result['comparison'] ?? null)) {
            $comparison = [
                'message' => $this->localizedText($result['comparison']['message'] ?? '', 220),
                'items' => array_map(
                    fn (array $item): array => [
                        'label' => $this->localizedText($item['label'] ?? '', 80),
                        'before' => is_numeric($item['before'] ?? null) ? (int) $item['before'] : null,
                        'after' => is_numeric($item['after'] ?? null) ? (int) $item['after'] : null,
                        'change' => is_numeric($item['change'] ?? null) ? (int) $item['change'] : null,
                    ],
                    array_slice(array_values(array_filter($result['comparison']['items'] ?? [], 'is_array')), 0, 9),
                ),
            ];
        }

        return [
            'survey' => [
                'definition_key' => $this->boundedText(data_get($result, 'survey.definition_key', ''), 80),
                'version' => is_numeric(data_get($result, 'survey.version')) ? (int) data_get($result, 'survey.version') : null,
                'methodology' => $this->boundedText(data_get($result, 'survey.methodology', ''), 100),
            ],
            'completed_at' => $this->boundedText($result['completed_at'] ?? '', 40),
            'attention_areas' => $attentionAreas,
            'metrics' => $metrics,
            'comparison' => $comparison,
        ];
    }

    private function textList(mixed $values, int $limit, int $itemLength): array
    {
        $result = [];
        foreach (array_slice(array_values(array_filter(is_array($values) ? $values : [], 'is_scalar')), 0, $limit) as $value) {
            $text = $this->boundedText($value, $itemLength);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return $result;
    }

    private function localizedText(mixed $value, int $limit): string
    {
        if (is_array($value)) {
            $value = $value['ru'] ?? $value['en'] ?? reset($value) ?: '';
        }

        return $this->boundedText($value, $limit);
    }

    private function boundedText(mixed $value, int $limit): string
    {
        $text = trim((string) $value);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }
}
