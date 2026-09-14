<?php

namespace App\Modules\AI\Application\Services;

use App\Filament\Support\ClinicalAiPresentation;
use App\Models\User;
use App\Modules\AI\Application\Actions\GetClinicalAiResult;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
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
    ) {}

    /**
     * @return array{
     *     explanation: string,
     *     states: array{
     *         documents: array{label: string, state: string, color: string},
     *         posture: array{label: string, state: string, color: string},
     *         synthesis: array{label: string, state: string, color: string, lastReadyAt: string|null}
     *     },
     *     readiness: list<array{label: string, available: bool, availability: string}>,
     *     synthesisPreview: string|null
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
        $synthesisRun = $this->latestRun($client, AiCapability::ClinicalSynthesizer, $organizationId);
        $latestReadySynthesis = $this->latestSuccessfulRun($client, AiCapability::ClinicalSynthesizer, $organizationId);

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
                    'lastReadyAt' => $this->dateLabel($latestReadySynthesis?->finished_at ?? $latestReadySynthesis?->created_at),
                ],
            ],
            'readiness' => $this->readinessFor($actor, $client, $organizationId),
            'synthesisPreview' => $this->synthesisPreview($actor, $client, $latestReadySynthesis),
        ];
    }

    /** @return list<array{label: string, available: bool, availability: string}>|null */
    public function readiness(User $actor, Client $client): ?array
    {
        $organization = $this->organizationFor($actor, $client);

        return $organization === null
            ? null
            : $this->readinessFor($actor, $client, (int) $organization->getKey());
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

    private function latestRun(Client $client, AiCapability $capability, int $organizationId): ?AiRun
    {
        return AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('capability', $capability)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first([
                'id',
                'organization_id',
                'client_id',
                'capability',
                'status',
                'human_review_status',
                'finished_at',
                'created_at',
            ]);
    }

    private function latestSuccessfulRun(Client $client, AiCapability $capability, int $organizationId): ?AiRun
    {
        return AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('capability', $capability)
            ->where('status', AiRunStatus::Succeeded)
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first([
                'id',
                'organization_id',
                'client_id',
                'capability',
                'status',
                'human_review_status',
                'finished_at',
                'created_at',
            ]);
    }

    /** @return list<array{label: string, available: bool, availability: string}> */
    private function readinessFor(User $actor, Client $client, int $organizationId): array
    {
        $profileAvailable = $this->profileAuthorization->allowsView($actor, $client)
            && MedicalProfile::query()
                ->where('organization_id', $organizationId)
                ->where('client_id', $client->getKey())
                ->exists();
        $documentAvailable = $this->hasReviewedRun($client, AiCapability::ClinicalDocumentExtraction, $organizationId);
        $postureAvailable = $this->hasReviewedRun($client, AiCapability::PostureAnalysis, $organizationId);
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
            [
                'label' => 'Проверенный анализ документов',
                'available' => $documentAvailable,
                'availability' => $documentAvailable ? 'доступен' : 'нет данных',
            ],
            [
                'label' => 'Проверенный анализ осанки',
                'available' => $postureAvailable,
                'availability' => $postureAvailable ? 'доступен' : 'нет данных',
            ],
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

    private function hasReviewedRun(Client $client, AiCapability $capability, int $organizationId): bool
    {
        return AiRun::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('capability', $capability)
            ->where('status', AiRunStatus::Succeeded)
            ->whereIn('human_review_status', [
                HumanReviewStatus::Accepted,
                HumanReviewStatus::EditedAndAccepted,
            ])
            ->exists();
    }

    private function synthesisPreview(User $actor, Client $client, ?AiRun $run): ?string
    {
        if ($run === null) {
            return null;
        }

        try {
            $result = $this->resultReader->handle($actor, (int) $run->getKey(), (int) $client->getKey());

            return ClinicalAiPresentation::preview($run->capability, $result->outputPayload, $result->outputText);
        } catch (Throwable) {
            return null;
        }
    }

    private function dateLabel(?CarbonInterface $date): ?string
    {
        return $date?->copy()->setTimezone($this->context->defaultTimezone())->format('d.m.Y H:i');
    }
}
