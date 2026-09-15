<?php

namespace App\Modules\AI\Application\Services;

use App\Filament\Support\ClinicalAiPresentation;
use App\Models\User;
use App\Modules\AI\Application\Actions\GetClinicalAiResult;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Application\MedicalProfileAuthorization;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Surveys\Application\SurveyAuthorization;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use Carbon\CarbonInterface;
use LogicException;
use Throwable;

final readonly class ReadClinicalAiClientSummary
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private MedicalProfileAuthorization $profileAuthorization,
        private SurveyAuthorization $surveyAuthorization,
        private GetClinicalAiResult $resultReader,
        private FindLatestReviewedAiRun $findLatestReviewedAiRun,
    ) {}

    /**
     * @return array{
     *     explanation: string,
     *     states: array{
     *         documents: array{label: string, state: string, color: string},
     *         posture: array{label: string, state: string, color: string},
     *         synthesis: array{label: string, state: string, color: string, lastReadyAt: string|null},
     *         courseReport: array{label: string, state: string, color: string, lastReadyAt: string|null}
     *     },
     *     readiness: list<array{label: string, available: bool, availability: string}>,
     *     synthesisPreview: string|null,
     *     synthesisPreviewAt: string|null
     * }|null
     */
    public function handle(User $actor, Client $client): ?array
    {
        $organization = $this->organizationFor($actor, $client);
        if ($organization === null) {
            return null;
        }

        $organizationId = (int) $organization->getKey();
        $documentRun = $this->latestRun($client, AiCapability::ClinicalDocumentExtraction, $organizationId);
        $postureRun = $this->latestRun($client, AiCapability::PostureAnalysis, $organizationId);
        $synthesisRun = $this->latestRun(
            $client,
            AiCapability::ClinicalSynthesizer,
            $organizationId,
            ClinicalSynthesizerWorkflow::Summary->value,
        );
        $courseReportRun = $this->latestRun(
            $client,
            AiCapability::ClinicalSynthesizer,
            $organizationId,
            ClinicalSynthesizerWorkflow::CourseReport->value,
        );
        $documentReviewedRun = $this->findLatestReviewedAiRun->handle(
            $client,
            AiCapability::ClinicalDocumentExtraction,
            $organizationId,
        );
        $postureReviewedRun = $this->findLatestReviewedAiRun->handle(
            $client,
            AiCapability::PostureAnalysis,
            $organizationId,
        );
        $synthesisReviewedRun = $this->findLatestReviewedAiRun->handle(
            $client,
            AiCapability::ClinicalSynthesizer,
            $organizationId,
            ClinicalSynthesizerWorkflow::Summary->value,
        );
        $courseReportReviewedRun = $this->findLatestReviewedAiRun->handle(
            $client,
            AiCapability::ClinicalSynthesizer,
            $organizationId,
            ClinicalSynthesizerWorkflow::CourseReport->value,
        );

        return [
            'explanation' => 'Анализы документов и осанки сначала проверяет специалист. Подтверждённые результаты вместе с медицинским профилем, сессиями и опросами используются для клинического резюме.',
            'states' => [
                'documents' => [
                    'label' => 'Анализ документов',
                    'state' => ClinicalAiPresentation::sourceStatus($documentRun),
                    'color' => ClinicalAiPresentation::sourceStatusColor($documentRun),
                ],
                'posture' => [
                    'label' => 'Анализ осанки',
                    'state' => ClinicalAiPresentation::sourceStatus($postureRun),
                    'color' => ClinicalAiPresentation::sourceStatusColor($postureRun),
                ],
                'synthesis' => [
                    'label' => 'Клиническое резюме',
                    'state' => ClinicalAiPresentation::synthesisStatus($synthesisRun),
                    'color' => ClinicalAiPresentation::synthesisStatusColor($synthesisRun),
                    'lastReadyAt' => $this->dateLabel($synthesisReviewedRun?->finished_at ?? $synthesisReviewedRun?->created_at),
                ],
                'courseReport' => [
                    'label' => 'Итоговый отчёт курса',
                    'state' => ClinicalAiPresentation::courseStatus($courseReportRun),
                    'color' => ClinicalAiPresentation::courseStatusColor($courseReportRun),
                    'lastReadyAt' => $this->dateLabel($courseReportReviewedRun?->finished_at ?? $courseReportReviewedRun?->created_at),
                ],
            ],
            'readiness' => $this->readinessFor(
                $actor,
                $client,
                $organizationId,
                $documentRun,
                $documentReviewedRun,
                $postureRun,
                $postureReviewedRun,
            ),
            'synthesisPreview' => $this->synthesisPreview($actor, $client, $synthesisReviewedRun),
            'synthesisPreviewAt' => $this->dateLabel($synthesisReviewedRun?->finished_at ?? $synthesisReviewedRun?->created_at),
        ];
    }

    /** @return list<array{label: string, available: bool, availability: string}>|null */
    public function readiness(User $actor, Client $client): ?array
    {
        $organization = $this->organizationFor($actor, $client);
        if ($organization === null) {
            return null;
        }

        $organizationId = (int) $organization->getKey();
        $documentRun = $this->latestRun($client, AiCapability::ClinicalDocumentExtraction, $organizationId);
        $postureRun = $this->latestRun($client, AiCapability::PostureAnalysis, $organizationId);
        $documentReviewedRun = $this->findLatestReviewedAiRun->handle(
            $client,
            AiCapability::ClinicalDocumentExtraction,
            $organizationId,
        );
        $postureReviewedRun = $this->findLatestReviewedAiRun->handle(
            $client,
            AiCapability::PostureAnalysis,
            $organizationId,
        );

        return $this->readinessFor(
            $actor,
            $client,
            $organizationId,
            $documentRun,
            $documentReviewedRun,
            $postureRun,
            $postureReviewedRun,
        );
    }

    public function readinessDescription(User $actor, Client $client): string
    {
        $readiness = $this->readiness($actor, $client);
        if ($readiness === null) {
            return 'Источники клинического резюме недоступны для текущего специалиста.';
        }

        $lines = ['Используется при наличии доступа:'];
        foreach ($readiness as $source) {
            $lines[] = $source['label'].': '.$source['availability'].'.';
        }

        return implode("\n", $lines);
    }

    private function organizationFor(User $actor, Client $client): ?Organization
    {
        try {
            $organization = $this->context->organization();

            if ((int) $client->organization_id !== (int) $organization->getKey()) {
                return null;
            }

            if (! $this->authorizer->allows($actor, $organization, OrganizationPermission::ViewClients)) {
                return null;
            }

            if (! $this->authorizer->allows($actor, $organization, OrganizationPermission::ViewAiRuns)) {
                return null;
            }

            return $organization;
        } catch (LogicException) {
            return null;
        }
    }

    private function latestRun(Client $client, AiCapability $capability, int $organizationId, ?string $workflowKey = null): ?AiRun
    {
        $query = AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('capability', $capability)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($capability === AiCapability::ClinicalSynthesizer) {
            $query->where('workflow_key', $workflowKey ?? ClinicalSynthesizerWorkflow::Summary->value);
        } elseif ($workflowKey !== null) {
            $query->where('workflow_key', $workflowKey);
        }

        return $query->first([
                'id',
                'organization_id',
                'client_id',
                'capability',
                'workflow_key',
                'status',
                'human_review_status',
                'finished_at',
                'created_at',
            ]);
    }

    /** @return list<array{label: string, available: bool, availability: string}> */
    private function readinessFor(
        User $actor,
        Client $client,
        int $organizationId,
        ?AiRun $documentRun,
        ?AiRun $documentReviewedRun,
        ?AiRun $postureRun,
        ?AiRun $postureReviewedRun,
    ): array {
        $profileAvailable = $this->profileAuthorization->allowsView($actor, $client)
            && MedicalProfile::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $client->getKey())
                ->exists();
        $sessionsAvailable = MedicalSession::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->exists();
        $surveysAllowed = $this->surveyAuthorization->allowsView($actor, $client);
        $surveysAvailable = $surveysAllowed && SurveyAttempt::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('status', SurveyAttemptStatus::Completed)
            ->whereNotNull('completed_at')
            ->exists();

        return [
            [
                'label' => 'Медицинский профиль',
                'available' => $profileAvailable,
                'availability' => $profileAvailable ? 'доступен' : 'нет данных',
            ],
            $this->sourceReadiness('Проверенный анализ документов', $documentReviewedRun !== null, $documentRun, $documentReviewedRun),
            $this->sourceReadiness('Проверенный анализ осанки', $postureReviewedRun !== null, $postureRun, $postureReviewedRun),
            [
                'label' => 'Последние сессии',
                'available' => $sessionsAvailable,
                'availability' => $sessionsAvailable ? 'доступны' : 'нет данных',
            ],
            [
                'label' => 'Завершённые опросы',
                'available' => $surveysAvailable,
                'availability' => $surveysAllowed
                    ? ($surveysAvailable ? 'доступны' : 'нет данных')
                    : 'недоступны для текущего специалиста',
            ],
        ];
    }

    /** @return array{label: string, available: bool, availability: string} */
    private function sourceReadiness(string $label, bool $available, ?AiRun $latestRun, ?AiRun $reviewedRun): array
    {
        $reviewedAt = $this->dateLabel($reviewedRun?->finished_at ?? $reviewedRun?->created_at);
        $usingPreviousReviewed = $available
            && $latestRun !== null
            && $reviewedRun !== null
            && (int) $latestRun->getKey() !== (int) $reviewedRun->getKey();
        $availability = match (true) {
            ! $available => 'проверенный результат отсутствует',
            $usingPreviousReviewed && $reviewedAt !== null => 'используется предыдущий проверенный результат от '.$reviewedAt,
            $usingPreviousReviewed => 'используется предыдущий проверенный результат',
            default => 'доступен',
        };

        return [
            'label' => $label,
            'available' => $available,
            'availability' => $availability,
        ];
    }

    private function synthesisPreview(User $actor, Client $client, ?AiRun $run): ?string
    {
        if ($run === null) {
            return null;
        }

        try {
            $result = $this->resultReader->handle($actor, (int) $run->getKey(), (int) $client->getKey());

            return ClinicalAiPresentation::preview(
                $run->capability,
                $result->outputPayload,
                $result->outputText,
                $run->workflow_key,
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function dateLabel(?CarbonInterface $date): ?string
    {
        return $date?->copy()->setTimezone($this->context->defaultTimezone())->format('d.m.Y H:i');
    }
}
