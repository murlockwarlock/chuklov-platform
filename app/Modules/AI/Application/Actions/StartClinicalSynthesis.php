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
            'anamnesis' => $profile->anamnesis ?? '',
            'complaints_goals' => $profile->complaintsGoals ?? '',
            'recent_sessions' => $sessionHistory,
            'agent_one_result' => $documentResult->outputPayload ?? [
                'status' => 'missing',
                'reason' => 'Проверенный анализ медицинского документа отсутствует.',
            ],
            'agent_two_result' => $postureResult->outputPayload ?? [
                'status' => 'missing',
                'reason' => 'Проверенный анализ осанки отсутствует.',
            ],
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
        foreach ($attempts as $attempt) {
            $references[] = new AiInputReference('survey_attempt', (int) $attempt->getKey());
            $results[] = [
                'attempt_id' => (int) $attempt->getKey(),
                'completed_at' => $attempt->completed_at?->toIso8601String(),
                'result' => $attempt->result_snapshot,
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
}
