<?php

namespace App\Modules\Feedback\Application;

use App\Models\User;
use App\Modules\Feedback\Domain\Enums\NpsBand;
use App\Modules\Feedback\Domain\Models\FeedbackSubmission;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Scenarios\Application\RecordScenarioEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordNpsSubmission
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly GetFeedbackConfiguration $configuration,
        private readonly FeedbackThemeCatalog $themes,
        private readonly RecordAuditEvent $audit,
        private readonly FeedbackRequestFingerprint $fingerprint,
        private readonly RecordScenarioEvent $scenarioEvents,
        private readonly OrganizationAuthorizer $authorizer,
    ) {}

    /** @param array<int, mixed> $themeIds */
    public function handle(
        Client $client,
        int $score,
        ?string $internalFeedback,
        string $idempotencyKey,
        string $source = 'portal',
        array $themeIds = [],
    ): FeedbackSubmission {
        return $this->record(
            client: $client,
            score: $score,
            internalFeedback: $internalFeedback,
            idempotencyKey: $idempotencyKey,
            source: $source,
            themeIds: $themeIds,
            auditActor: null,
            dispatchScenario: true,
        );
    }

    /** @param array<int, mixed> $themeIds */
    public function handleForStaff(
        User $actor,
        Client $client,
        int $score,
        ?string $internalFeedback,
        string $idempotencyKey,
        array $themeIds = [],
    ): FeedbackSubmission {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageClients);

        return $this->record(
            client: $client,
            score: $score,
            internalFeedback: $internalFeedback,
            idempotencyKey: $idempotencyKey,
            source: 'crm',
            themeIds: $themeIds,
            auditActor: $actor,
            dispatchScenario: false,
        );
    }

    /** @param array<int, mixed> $themeIds */
    private function record(
        Client $client,
        int $score,
        ?string $internalFeedback,
        string $idempotencyKey,
        string $source,
        array $themeIds,
        ?User $auditActor,
        bool $dispatchScenario,
    ): FeedbackSubmission {
        $organization = $this->context->organization();
        abort_unless((int) $client->organization_id === (int) $organization->getKey(), 404);
        $settings = $this->configuration->handle();

        if (! $settings['enabled']) {
            throw ValidationException::withMessages(['score' => 'Обратная связь сейчас недоступна.']);
        }

        try {
            $band = NpsBand::fromScore($score, $settings['positiveThreshold']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['score' => 'Оценка должна быть от 0 до 10.']);
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'Ключ отправки указан неверно.']);
        }

        $source = strtolower(trim($source));
        if ($source === '' || mb_strlen($source) > 40 || preg_match('/^[a-z0-9._:-]+$/', $source) !== 1) {
            throw ValidationException::withMessages(['source' => 'Источник отправки указан неверно.']);
        }

        $themeIds = $this->themes->assertKnown((int) $organization->getKey(), $themeIds);

        $internalFeedback = $internalFeedback === null ? null : trim($internalFeedback);
        if ($internalFeedback !== null && mb_strlen($internalFeedback) > 4000) {
            throw ValidationException::withMessages(['internal_feedback' => 'Текст слишком длинный.']);
        }

        if ($band === NpsBand::Internal && $settings['lowScoreFeedbackRequired'] && ($internalFeedback === null || $internalFeedback === '')) {
            throw ValidationException::withMessages(['internal_feedback' => 'Расскажите, что можно улучшить.']);
        }

        if ($band === NpsBand::Positive) {
            $internalFeedback = null;
        }

        $requestHash = $this->fingerprint->handle([
            'client_id' => $client->getKey(),
            'score' => $score,
            'internal_feedback' => $internalFeedback,
            'source' => $source,
            'theme_ids' => $themeIds,
        ]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($organization, $client, $score, $internalFeedback, $idempotencyKey, $requestHash, $source, $band, $themeIds, $auditActor, $dispatchScenario): FeedbackSubmission {
                    $existing = FeedbackSubmission::query()
                        ->where('organization_id', $organization->getKey())
                        ->where('client_id', $client->getKey())
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing instanceof FeedbackSubmission) {
                        if ($existing->request_hash !== $requestHash) {
                            throw ValidationException::withMessages(['idempotency_key' => 'Этот ключ уже использован для другой оценки.']);
                        }

                        return $existing;
                    }

                    $submission = new FeedbackSubmission;
                    $submission->forceFill([
                        'organization_id' => $organization->getKey(),
                        'client_id' => $client->getKey(),
                        'score' => $score,
                        'source' => $source,
                        'idempotency_key' => $idempotencyKey,
                        'request_hash' => $requestHash,
                        'internal_feedback' => $internalFeedback,
                        'submitted_at' => now(),
                    ]);
                    $submission->save();
                    $submission->themes()->syncWithPivotValues($themeIds, [
                        'organization_id' => $organization->getKey(),
                        'client_id' => $client->getKey(),
                    ]);
                    $this->audit->handle(
                        organization: $organization,
                        actor: $auditActor,
                        action: 'feedback.submitted',
                        targetType: FeedbackSubmission::class,
                        targetId: (string) $submission->getKey(),
                        metadata: [
                            'score' => $score,
                            'band' => $band->value,
                            'source' => $source,
                            'has_internal_feedback' => $internalFeedback !== null && $internalFeedback !== '',
                            'theme_count' => count($themeIds),
                        ],
                    );
                    if ($dispatchScenario) {
                        $this->scenarioEvents->feedbackSubmitted($submission, $band, now()->toImmutable());
                    }

                    return $submission->refresh();
                });
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === 2) {
                    throw $exception;
                }
            }
        }

        throw new \LogicException('A feedback submission could not be recorded.');
    }
}
