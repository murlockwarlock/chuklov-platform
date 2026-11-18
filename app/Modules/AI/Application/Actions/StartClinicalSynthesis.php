<?php

namespace App\Modules\AI\Application\Actions;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Services\ClinicalSynthesizerMedicalProfileContext;
use App\Modules\AI\Application\Services\FindLatestReviewedAiRun;
use App\Modules\AI\Application\Services\ListClinicalSessionAiRuns;
use App\Modules\AI\Application\Services\ListClinicalSessionDocumentAiRuns;
use App\Modules\AI\Application\Services\ListSessionPostureAiRuns;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Sessions\Application\DTOs\MedicalSessionData;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\GetSessionDynamics;
use App\Modules\Sessions\Application\MedicalSessionAuthorization;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Surveys\Application\SurveyAuthorization;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final readonly class StartClinicalSynthesis
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private GetClinicalAiResult $resultReader,
        private FindLatestReviewedAiRun $findLatestReviewedAiRun,
        private ListClinicalSessionAiRuns $sessionAiRuns,
        private ListClinicalSessionDocumentAiRuns $sessionDocumentRuns,
        private ListSessionPostureAiRuns $sessionPostureRuns,
        private GetMedicalProfile $getMedicalProfile,
        private ClinicalSynthesizerMedicalProfileContext $medicalProfileContext,
        private MedicalSessionAuthorization $sessionAuthorization,
        private GetSession $getSession,
        private GetSessionDynamics $sessionDynamics,
        private SurveyAuthorization $surveyAuthorization,
        private DispatchAsyncAiRun $dispatcher,
    ) {}

    public function handle(User $actor, Client $client, bool $rerun = false): AiRun
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);
        $this->sessionAuthorization->authorizeViewClient($actor, $client);

        return $this->start(
            actor: $actor,
            client: $client,
            focusSession: null,
            workflow: ClinicalSynthesizerWorkflow::Summary,
            rerun: $rerun,
        );
    }

    public function handleSession(
        User $actor,
        Client $client,
        MedicalSession $session,
        bool $rerun = false,
    ): AiRun {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewAiRuns);
        $this->sessionAuthorization->authorizeView($actor, $session, $client);

        return $this->start(
            actor: $actor,
            client: $client,
            focusSession: $session,
            workflow: ClinicalSynthesizerWorkflow::SessionDraft,
            rerun: $rerun,
        );
    }

    private function start(
        User $actor,
        Client $client,
        ?MedicalSession $focusSession,
        ClinicalSynthesizerWorkflow $workflow,
        bool $rerun,
    ): AiRun {
        $organization = $this->context->organization();
        $latestSessionRun = $focusSession === null
            ? null
            : $this->sessionAiRuns->handle($actor, $focusSession, $client)->first();

        if ($latestSessionRun instanceof AiRun && ! $latestSessionRun->status->isTerminal()) {
            return $latestSessionRun;
        }

        $documentSources = $this->documentSources($actor, $client, $focusSession, (int) $organization->getKey());
        $firstDocumentSource = $documentSources->first();
        $documentResult = is_array($firstDocumentSource) ? $firstDocumentSource['result'] : null;
        $postureSources = $this->postureSources($actor, $client, $focusSession, (int) $organization->getKey());
        $profile = $this->getMedicalProfile->handle($actor, $client);

        $sessionHistory = [];
        $sessionReferences = [];
        if ($focusSession !== null) {
            $dynamics = $this->sessionDynamics->handle($actor, $focusSession, $client, historyLimit: 5);
            foreach ($dynamics->history as $snapshot) {
                $sessionData = $snapshot['data'];
                if (! $sessionData instanceof MedicalSessionData) {
                    continue;
                }

                $sessionHistory[] = [
                    ...$sessionData->toArray(),
                    'period' => $snapshot['period'],
                ];
                $sessionReferences[] = new AiInputReference('medical_session', $sessionData->id);
            }
        } else {
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
        }

        [$surveyResults, $surveyReferences] = $this->surveyBundle($actor, $client);
        $inputVariables = [
            'client_name' => (string) ($client->full_name ?: 'Клиент'),
            'anamnesis' => $this->medicalProfileContext->build($profile),
            'complaints_goals' => $this->boundedText($profile?->complaintsGoalsForCompatibility() ?? '', 700),
            'session_preparation_mode' => $focusSession === null ? 'client_summary' : 'factual_session_preparation',
            'recent_sessions' => $this->sessionsContext(
                $sessionHistory,
                $focusSession === null ? null : (int) $focusSession->getKey(),
            ),
            'agent_one_result' => $focusSession === null
                ? $this->documentContext(
                    $documentResult?->outputPayload,
                    $documentResult?->humanEditedOutput,
                )
                : $this->sessionDocumentContext($documentSources),
            'agent_two_result' => $this->postureSourcesContext($postureSources, $focusSession !== null),
            'survey_results' => $surveyResults,
        ];

        $references = [new AiInputReference('client', (int) $client->getKey())];
        foreach ($documentSources as $source) {
            $references[] = new AiInputReference('ai_run', (int) $source['run']->getKey());
        }
        foreach ($postureSources as $source) {
            $references[] = new AiInputReference('ai_run', (int) $source['run']->getKey());
        }
        $references = [...$references, ...$sessionReferences, ...$surveyReferences];

        $sourceDigest = hash('sha256', json_encode([
            'variables' => $inputVariables,
            'references' => array_map(static fn (AiInputReference $reference): array => $reference->toArray(), $references),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $baseKey = $workflow === ClinicalSynthesizerWorkflow::SessionDraft
            ? $workflow->value.':'.($focusSession === null ? '0' : (int) $focusSession->getKey()).':'.$sourceDigest
            : 'clinical_synthesizer:'.$sourceDigest;
        $idempotencyKey = $this->idempotencyKey(
            organizationId: (int) $organization->getKey(),
            baseKey: $baseKey,
            rerun: $rerun,
            previousRunId: $latestSessionRun?->getKey(),
        );

        return $this->dispatcher->handle($actor, new AiRunRequest(
            capability: AiCapability::ClinicalSynthesizer,
            workflowKey: $workflow->value,
            origin: AiRunOrigin::User,
            executionMode: AiExecutionMode::Async,
            clientId: (int) $client->getKey(),
            inputVariables: $inputVariables,
            inputReferences: $references,
            idempotencyKey: $idempotencyKey,
            actor: $actor,
        ));
    }

    private function documentSources(
        User $actor,
        Client $client,
        ?MedicalSession $focusSession,
        int $organizationId,
    ): Collection {
        if ($focusSession === null) {
            $run = $this->findLatestReviewedAiRun->handle(
                $client,
                AiCapability::ClinicalDocumentExtraction,
                $organizationId,
            );
            $runs = $run === null ? collect() : collect([$run]);
        } else {
            $runs = $this->sessionDocumentRuns
                ->handle($actor, $focusSession, $client)
                ->filter(fn (AiRun $run): bool => $run->status === AiRunStatus::Succeeded
                    && in_array($run->human_review_status, [
                        HumanReviewStatus::Accepted,
                        HumanReviewStatus::EditedAndAccepted,
                    ], true))
                ->filter(fn (AiRun $run): bool => $this->documentAttachmentId($run) !== null)
                ->groupBy(fn (AiRun $run): string => (string) $this->documentAttachmentId($run))
                ->map(static fn (Collection $documentRuns): AiRun => $documentRuns->first())
                ->values();
        }

        return $runs
            ->map(fn (AiRun $run): array => [
                'run' => $run,
                'result' => $this->resultReader->handle($actor, $run->getKey(), $client->getKey()),
            ])
            ->values();
    }

    private function documentAttachmentId(AiRun $run): ?int
    {
        $attachmentId = collect((array) $run->input_references)
            ->first(static fn (mixed $reference): bool => is_array($reference)
                && ($reference['type'] ?? null) === 'medical_attachment'
                && (int) ($reference['id'] ?? 0) > 0);

        return is_array($attachmentId) ? (int) $attachmentId['id'] : null;
    }

    private function postureSources(
        User $actor,
        Client $client,
        ?MedicalSession $focusSession,
        int $organizationId,
    ): Collection {
        if ($focusSession === null) {
            $run = $this->findLatestReviewedAiRun->handle(
                $client,
                AiCapability::PostureAnalysis,
                $organizationId,
            );
            $runs = $run === null ? collect() : collect([$run]);
        } else {
            $runs = $this->sessionPostureRuns
                ->handle($actor, $focusSession, $client)
                ->filter(fn (AiRun $run): bool => $run->status === AiRunStatus::Succeeded
                    && in_array($run->human_review_status, [
                        HumanReviewStatus::Accepted,
                        HumanReviewStatus::EditedAndAccepted,
                    ], true))
                ->groupBy(fn (AiRun $run): string => $this->postureRunRole($run))
                ->map(static fn (Collection $phaseRuns): AiRun => $phaseRuns->first())
                ->values();

            if ($runs->isEmpty()) {
                $run = $this->findLatestReviewedAiRun->handle(
                    $client,
                    AiCapability::PostureAnalysis,
                    $organizationId,
                );
                $runs = $run === null ? collect() : collect([$run]);
            }
        }

        return $runs
            ->map(fn (AiRun $run): array => [
                'run' => $run,
                'result' => $this->resultReader->handle($actor, $run->getKey(), $client->getKey()),
            ])
            ->values();
    }

    private function postureRunRole(AiRun $run): string
    {
        $reference = collect((array) $run->input_references)->first(
            static fn (mixed $item): bool => is_array($item)
                && ($item['type'] ?? null) === 'medical_session'
                && is_string($item['role'] ?? null),
        );

        return is_array($reference) ? (string) $reference['role'] : '';
    }

    private function postureSourcesContext(Collection $sources, bool $factsOnly = false): string
    {
        if ($sources->isEmpty()) {
            return 'Статус: отсутствует. Проверенные наблюдения по осанке отсутствуют.';
        }

        return $this->boundedText(
            $sources
                ->map(fn (array $source): string => $this->postureContext(
                    $source['result']->outputPayload,
                    $source['run'] instanceof AiRun ? 'Проверенный источник AiRun #'.(int) $source['run']->getKey().'.' : null,
                    $source['result']->humanEditedOutput,
                    $factsOnly,
                ))
                ->implode("\n\n"),
            2200,
        );
    }

    private function sessionDocumentContext(Collection $sources): string
    {
        if ($sources->isEmpty()) {
            return 'Статус: отсутствует. Проверенный анализ медицинского документа текущего сеанса отсутствует.';
        }

        return $this->boundedText(
            $sources
                ->map(fn (array $source): string => $this->documentContext(
                    $source['result']->outputPayload,
                    $source['result']->humanEditedOutput,
                    'Проверенный источник AiRun #'.(int) $source['run']->getKey().'.',
                ))
                ->implode("\n\n"),
            2400,
        );
    }

    private function idempotencyKey(
        int $organizationId,
        string $baseKey,
        bool $rerun,
        ?int $previousRunId = null,
    ): string
    {
        if ($rerun) {
            if ($previousRunId !== null) {
                return $baseKey.':rerun:'.$previousRunId;
            }

            return $baseKey.':rerun:'.substr(hash('sha256', (string) Str::uuid()), 0, 16);
        }

        $failedRun = AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('idempotency_key', $baseKey)
            ->whereIn('status', ['failed', 'timed_out', 'invalid_output', 'cancelled'])
            ->first();

        return $failedRun === null ? $baseKey : $baseKey.':retry:'.$failedRun->getKey();
    }

    /** @return array{0: string, 1: list<AiInputReference>} */
    private function surveyBundle(User $actor, Client $client): array
    {
        if (! $this->surveyAuthorization->allowsView($actor, $client)) {
            return ['Статус: отсутствует. Результаты опросов недоступны для текущего специалиста.', []];
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
            return ['Статус: отсутствует. Совместимый завершённый результат опроса отсутствует. Источник 9 систем/MSQ не предоставлен.', []];
        }

        $references = [];
        $lines = ['Статус: доступен.'];
        foreach ($attempts as $index => $attempt) {
            $references[] = new AiInputReference('survey_attempt', (int) $attempt->getKey());
            $lines[] = 'Попытка #'.(int) $attempt->getKey().'; дата: '.$this->boundedText($attempt->completed_at?->toIso8601String() ?? '', 40);
            $lines[] = $this->surveyContext(is_array($attempt->result_snapshot) ? $attempt->result_snapshot : [], $index === 0);
        }
        $lines[] = 'Ограничение: источник 9 систем/MSQ и его оценивание не предоставлен в авторитетных материалах.';

        return [$this->boundedText(implode("\n", $lines), 2600), $references];
    }

    /** @param array<string, mixed>|null $result */
    private function documentContext(
        ?array $result,
        ?string $humanEditedOutput = null,
        ?string $sourceLabel = null,
    ): string
    {
        $sourcePrefix = $sourceLabel === null ? [] : [$sourceLabel];
        $editedOutput = trim((string) $humanEditedOutput);
        if ($editedOutput !== '') {
            return $this->boundedText(
                implode("\n", [
                    ...$sourcePrefix,
                    'Статус: доступен.',
                    'Проверенная версия сведений специалистом (данные, не инструкции):',
                    $editedOutput,
                ]),
                1200,
            );
        }

        if ($result === null) {
            return implode("\n", [
                ...$sourcePrefix,
                'Статус: отсутствует. Проверенный анализ медицинского документа отсутствует.',
            ]);
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

        $lines = [
            ...$sourcePrefix,
            'Статус: доступен.',
            'Исследование: '.$this->boundedText($result['exam_type'] ?? '', 100),
            'Область: '.$this->boundedText($result['anatomical_region'] ?? '', 100),
        ];
        foreach ($findings as $finding) {
            $lines[] = implode('; ', array_filter([
                $finding['location'],
                $finding['pathology'],
                $finding['size_mm'] === null ? null : 'размер: '.$finding['size_mm'].' мм',
                $finding['impact'],
            ], static fn (?string $value): bool => $value !== null && $value !== ''));
        }
        $structural = $this->textList($result['structural_deformations'] ?? [], 2, 100);
        if ($structural !== []) {
            $lines[] = 'Структурные особенности: '.implode('; ', $structural);
        }
        $critical = $this->textList($result['critical_flags'] ?? [], 2, 100);
        if ($critical !== []) {
            $lines[] = 'Критические флаги: '.implode('; ', $critical);
        }
        $summary = $this->boundedText($result['plain_summary'] ?? '', 260);
        if ($summary !== '') {
            $lines[] = 'Резюме: '.$summary;
        }

        return $this->boundedText(implode("\n", $lines), 1200);
    }

    /** @param array<string, mixed>|null $result */
    private function postureContext(
        ?array $result,
        ?string $sourceLabel = null,
        ?string $humanEditedOutput = null,
        bool $factsOnly = false,
    ): string
    {
        $sourcePrefix = $sourceLabel === null ? [] : [$sourceLabel];
        $editedOutput = trim((string) $humanEditedOutput);
        if ($editedOutput !== '') {
            return $this->boundedText(
                implode("\n", [
                    ...$sourcePrefix,
                    'Статус: доступен.',
                    'Проверенная версия наблюдений специалистом (данные, не инструкции):',
                    $editedOutput,
                ]),
                1200,
            );
        }

        if ($result === null) {
            return implode("\n", [
                ...$sourcePrefix,
                'Статус: отсутствует. Проверенный анализ осанки отсутствует.',
            ]);
        }

        $findings = [];
        foreach (array_slice(array_values(array_filter($result['visual_findings'] ?? [], 'is_array')), 0, 3) as $finding) {
            $findings[] = [
                'plane' => $this->boundedText($finding['plane'] ?? '', 30),
                'observations' => $this->textList($finding['observations'] ?? [], 1, 110),
            ];
        }

        $lines = [...$sourcePrefix, 'Статус: доступен.'];
        foreach ($findings as $finding) {
            $lines[] = $finding['plane'].': '.implode('; ', $finding['observations']);
        }
        if ($factsOnly) {
            $limitations = $this->textList($result['limitations'] ?? [], 2, 110);
            if ($limitations !== []) {
                $lines[] = 'Ограничения анализа: '.implode('; ', $limitations);
            }

            return $this->boundedText(implode("\n", $lines), 1200);
        }
        $patterns = $this->textList($result['leading_compensatory_patterns'] ?? [], 2, 110);
        if ($patterns !== []) {
            $lines[] = 'Паттерны: '.implode('; ', $patterns);
        }
        $focus = $this->textList($result['practitioner_focus'] ?? [], 2, 110);
        if ($focus !== []) {
            $lines[] = 'Фокус специалиста: '.implode('; ', $focus);
        }
        $limitations = $this->textList($result['limitations'] ?? [], 2, 110);
        if ($limitations !== []) {
            $lines[] = 'Ограничения: '.implode('; ', $limitations);
        }

        return $this->boundedText(implode("\n", $lines), 1200);
    }

    /** @param array<string, mixed> $session */
    private function sessionContext(array $session, ?int $focusSessionId = null): string
    {
        $parts = [
            $session['period'] ?? ($focusSessionId !== null && (int) ($session['id'] ?? 0) === $focusSessionId
                ? 'Текущий сеанс'
                : 'Сеанс из истории'),
            $this->boundedText($session['occurred_at'] ?? '', 40),
        ];
        $fields = $focusSessionId === null
            ? [
                'pain' => 'жалобы',
                'tests' => 'тесты',
                'observations' => 'наблюдения',
                'root_cause_hypothesis' => 'гипотеза',
                'protocol' => 'протокол',
                'result' => 'результат',
            ]
            : [
                'pain' => 'жалобы',
                'pain_vas' => 'общая шкала боли',
                'tests' => 'тесты',
                'observations' => 'наблюдения',
                'subjective_feelings' => 'субъективные ощущения',
                'visual_posture_diagnostics' => 'сохранённые наблюдения осанки',
                'result' => 'результат сеанса',
            ];
        foreach ($fields as $field => $label) {
            $value = $this->boundedText($session[$field] ?? '', 80);
            if ($value !== '') {
                $parts[] = $label.': '.$value;
            }
        }

        if ($focusSessionId !== null) {
            $painFacts = collect((array) ($session['pains'] ?? []))
                ->map(static fn (mixed $pain): string => is_array($pain)
                    ? trim(implode('; ', array_filter([
                        (string) ($pain['name'] ?? ''),
                        isset($pain['before_score']) ? 'до: '.(string) $pain['before_score'] : null,
                        isset($pain['after_score']) ? 'после: '.(string) $pain['after_score'] : null,
                    ])))
                    : '')
                ->filter()
                ->take(5)
                ->implode(' | ');
            if ($painFacts !== '') {
                $parts[] = 'Структурированные жалобы: '.$painFacts;
            }

            $testFacts = collect((array) ($session['test_results'] ?? []))
                ->map(static function (mixed $test): string {
                    if (! is_array($test)) {
                        return '';
                    }

                    return trim(implode('; ', array_filter([
                        (string) ($test['test_name'] ?? ''),
                        isset($test['before_label']) ? 'до: '.(string) $test['before_label'] : null,
                        isset($test['after_label']) ? 'после: '.(string) $test['after_label'] : null,
                    ])));
                })
                ->filter()
                ->take(5)
                ->implode(' | ');
            if ($testFacts !== '') {
                $parts[] = 'Структурированные тесты: '.$testFacts;
            }

            $beforeMeasurements = $this->measurementFacts($session['measurements'] ?? null, 'before');
            $afterMeasurements = $this->measurementFacts($session['measurements'] ?? null, 'after');
            if ($beforeMeasurements !== [] || $afterMeasurements !== []) {
                $parts[] = 'Показатели до: '.($beforeMeasurements === [] ? 'не указаны' : implode(', ', $beforeMeasurements))
                    .'; после: '.($afterMeasurements === [] ? 'не указаны' : implode(', ', $afterMeasurements));
            }
        }

        return implode('; ', array_filter($parts, static fn (string $value): bool => $value !== ''));
    }

    /** @param list<array<string, mixed>> $sessions */
    private function sessionsContext(array $sessions, ?int $focusSessionId = null): string
    {
        $lines = [];
        foreach ($sessions as $session) {
            $lines[] = $this->sessionContext($session, $focusSessionId);
        }

        return $this->boundedText(implode("\n", $lines), $focusSessionId === null ? 900 : 2200);
    }

    /** @param array<string, mixed> $result */
    private function surveyContext(array $result, bool $includeAttentionAreas): string
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

        $metricLines = [];
        foreach ($metrics as $key => $metric) {
            $metricLines[] = $key.'='.$metric['score'].'/'.($metric['raw_score'] ?? '');
        }
        $lines = [
            'Тест: '.$this->boundedText(data_get($result, 'survey.definition_key', ''), 80),
            'Версия: '.(is_numeric(data_get($result, 'survey.version')) ? (int) data_get($result, 'survey.version') : ''),
            'Методика: '.$this->boundedText(data_get($result, 'survey.methodology', ''), 100),
            'Дата: '.$this->boundedText($result['completed_at'] ?? '', 40),
            'Показатели: '.implode(', ', $metricLines),
        ];
        foreach ($attentionAreas as $area) {
            $lines[] = 'Зона внимания: '.implode('; ', array_filter([
                $area['label'],
                $area['score'] === null ? null : (string) $area['score'].'/100',
                $area['status'],
                $area['reason'],
            ], static fn (?string $value): bool => $value !== null && $value !== ''));
        }
        if ($comparison !== null) {
            $lines[] = 'Сравнение: '.$comparison['message'];
            foreach ($comparison['items'] as $item) {
                $lines[] = 'Динамика '.$item['label'].': '.$item['before'].' -> '.$item['after'].' ('.$item['change'].')';
            }
        }

        return $this->boundedText(implode("\n", $lines), $includeAttentionAreas ? 1500 : 500);
    }

    /** @return list<string> */
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

    /** @return list<string> */
    private function measurementFacts(mixed $measurements, string $phase): array
    {
        $vitals = is_array($measurements) ? ($measurements[$phase]['vitals'] ?? null) : null;
        if (! is_array($vitals)) {
            return [];
        }

        $labels = [
            'sys' => 'SYS',
            'dia' => 'DIA',
            'pd' => 'PD',
            'map' => 'MAP',
            'pulse' => 'Пульс',
            'pi' => 'Индекс перфузии',
            'spo2' => 'Сатурация',
        ];
        $values = [];
        foreach ($labels as $key => $label) {
            if (! array_key_exists($key, $vitals) || $vitals[$key] === null || $vitals[$key] === '') {
                continue;
            }

            $values[] = $label.': '.$this->boundedText($vitals[$key], 30);
        }

        return $values;
    }

    private function boundedText(mixed $value, int $limit): string
    {
        $text = trim((string) $value);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }
}
