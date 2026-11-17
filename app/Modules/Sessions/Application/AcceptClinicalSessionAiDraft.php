<?php

namespace App\Modules\Sessions\Application;

use App\Models\User;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewReasonCode;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunHumanReview;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Domain\Enums\ClinicalSessionAiDraftTarget;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AcceptClinicalSessionAiDraft
{
    public function __construct(
        private MedicalSessionAuthorization $authorization,
        private ReviewAiRun $review,
        private GetSession $getSession,
        private UpdateSession $updateSession,
    ) {}

    public function handle(
        User $actor,
        MedicalSession $session,
        Client $client,
        int $runId,
        ClinicalSessionAiDraftTarget $target,
        string $editedOutput,
        ?string $expectedSnapshot,
    ): AiRunHumanReview {
        $organization = $this->authorization->authorizeManageSession($actor, $session, $client);
        $reviewOrganization = $this->review->authorize($actor);
        if ((int) $reviewOrganization->getKey() !== (int) $organization->getKey()) {
            throw ValidationException::withMessages([
                'review' => 'Результат AI недоступен в текущей организации.',
            ]);
        }

        if (! $target->isAvailable()) {
            throw ValidationException::withMessages([
                'target' => $target->unavailableReason() ?? 'Этот перенос AI пока недоступен.',
            ]);
        }

        $editedOutput = trim($editedOutput);
        if ($editedOutput === '') {
            throw ValidationException::withMessages([
                'edited_output' => 'Введите текст перед принятием черновика.',
            ]);
        }
        if (mb_strlen($editedOutput) > MedicalSessionFieldLimits::MaxTextLength) {
            throw ValidationException::withMessages([
                'edited_output' => 'Поле превышает максимальную длину в '.MedicalSessionFieldLimits::MaxTextLength.' символов.',
            ]);
        }
        if ($expectedSnapshot === null || trim($expectedSnapshot) === '') {
            throw ValidationException::withMessages([
                'session' => 'Обновите карточку сеанса перед переносом черновика.',
            ]);
        }

        return DB::transaction(function () use ($actor, $client, $organization, $session, $runId, $target, $editedOutput, $expectedSnapshot): AiRunHumanReview {
            /** @var AiRun|null $run */
            $run = AiRun::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();

            if ($run === null) {
                throw ValidationException::withMessages([
                    'run' => 'Черновик AI не найден в этом сеансе.',
                ]);
            }
            if ($run->capability !== AiCapability::ClinicalSynthesizer
                || $run->workflow_key !== ClinicalSynthesizerWorkflow::SessionDraft->value
                || $run->status !== AiRunStatus::Succeeded
                || $run->human_review_status !== HumanReviewStatus::PendingReview) {
                throw ValidationException::withMessages([
                    'run' => 'Этот черновик AI уже недоступен для принятия.',
                ]);
            }
            if (! collect((array) $run->input_references)->contains(static fn (mixed $reference): bool => is_array($reference)
                && ($reference['type'] ?? null) === 'medical_session'
                && (int) ($reference['id'] ?? 0) === (int) $session->getKey())) {
                throw ValidationException::withMessages([
                    'run' => 'Черновик AI не относится к текущему сеансу.',
                ]);
            }
            $payloadExists = AiRunPayload::query()
                ->where('organization_id', $organization->getKey())
                ->where('ai_run_id', $run->getKey())
                ->where(function ($query): void {
                    $query
                        ->whereNotNull('encrypted_output_text')
                        ->orWhereNotNull('encrypted_output_payload');
                })
                ->exists();
            if (! $payloadExists) {
                throw ValidationException::withMessages([
                    'run' => 'Черновик AI не содержит доступного результата. Повторите подготовку.',
                ]);
            }

            /** @var MedicalSession|null $lockedSession */
            $lockedSession = MedicalSession::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->first();
            if ($lockedSession === null) {
                throw ValidationException::withMessages([
                    'session' => 'Медицинский сеанс не найден.',
                ]);
            }
            if (! hash_equals($expectedSnapshot, app(MedicalSessionSnapshotHasher::class)->forSession($lockedSession))) {
                throw ValidationException::withMessages([
                    'session' => 'Медицинский сеанс изменился в другой вкладке. Обновите его перед переносом.',
                ]);
            }

            $review = $this->review->handleWithinTransaction(
                organization: $organization,
                actor: $actor,
                lockedRun: $run,
                decision: HumanReviewDecision::EditedAndAccepted,
                reasonCode: HumanReviewReasonCode::SpecialistEdited,
                editedOutput: $editedOutput,
                targetField: $target->value,
            );

            $this->getSession->invalidate($actor->getKey(), (int) $organization->getKey(), (int) $lockedSession->getKey());
            $current = $this->getSession->handle($actor, $lockedSession, $client);
            if ($current === null) {
                throw ValidationException::withMessages([
                    'session' => 'Не удалось прочитать текущие данные сеанса.',
                ]);
            }

            $protocolSteps = $target === ClinicalSessionAiDraftTarget::ProtocolSteps
                ? [...$current->protocolSteps, [
                    'lineage_key' => (string) Str::uuid(),
                    'text' => $editedOutput,
                ]]
                : null;
            $this->updateSession->handle(
                actor: $actor,
                session: $lockedSession,
                command: new UpdateSessionCommand(
                    pain: $current->pain,
                    tests: $current->tests,
                    observations: $current->observations,
                    rootCauseHypothesis: $target === ClinicalSessionAiDraftTarget::RootCauseHypothesis
                        ? $editedOutput
                        : $current->rootCauseHypothesis,
                    protocol: $current->protocol,
                    result: $target === ClinicalSessionAiDraftTarget::Result
                        ? $editedOutput
                        : $current->result,
                    expectedSnapshot: $expectedSnapshot,
                    protocolSteps: $protocolSteps,
                    protocolStepsProvided: $protocolSteps !== null,
                ),
                expectedClient: $client,
            );

            return $review;
        });
    }
}
