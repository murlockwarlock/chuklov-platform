<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ClinicalSynthesizerWorkflow;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewReasonCode;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Sessions\Application\CreateSession;
use App\Modules\Sessions\Application\DTOs\CreateSessionCommand;
use App\Modules\Sessions\Application\DTOs\UpdateSessionCommand;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Application\MedicalSessionSnapshotHasher;
use App\Modules\Sessions\Application\RejectClinicalSessionAiDraft;
use App\Modules\Sessions\Application\UpdateSession;
use App\Modules\Sessions\Domain\Enums\ClinicalSessionAiDraftTarget;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Specialists\Domain\Models\Specialist;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class AiHumanReviewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $specialistUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Chuklov Clinic',
            'slug' => 'chuklov-clinic',
        ]);

        $this->specialistUser = User::factory()->forOrganization($this->organization, OrganizationRole::Staff)->create();

        config()->set('tenancy.default_organization_id', $this->organization->id);
        app(OrganizationContext::class)->set($this->organization);
    }

    public function test_specialist_reviews_and_edits_ai_proposal(): void
    {
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClinicalSynthesizer,
            'workflow_key' => 'synthesis_review_test',
            'origin' => AiRunOrigin::User,
            'status' => AiRunStatus::Succeeded,
            'human_review_status' => HumanReviewStatus::PendingReview,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $payload = AiRunPayload::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'encryption_key_version' => 1,
            'encrypted_output_text' => 'ciphertext_placeholder',
        ]);

        /** @var ReviewAiRun $reviewAction */
        $reviewAction = app(ReviewAiRun::class);

        $review = $reviewAction->handle(
            actor: $this->specialistUser,
            runId: $run->id,
            decision: HumanReviewDecision::EditedAndAccepted,
            safeReasonCode: 'specialist_edited',
            notes: 'Скорректирован протокол ЛФК с учетом гипертонуса.',
            editedOutput: 'Итоговый подтвержденный специалистом протокол',
        );

        $this->assertSame(HumanReviewDecision::EditedAndAccepted, $review->decision);
        $this->assertSame('specialist_edited', $review->safe_reason_code);

        $run->refresh();
        $this->assertSame(HumanReviewStatus::EditedAndAccepted, $run->human_review_status);

        // Verify notes and edited output are encrypted in ai_run_payloads
        $payload->refresh();
        $this->assertNotNull($payload->encrypted_human_review_notes);
        $this->assertNotNull($payload->encrypted_human_edited_output);
        $this->assertNotSame('Скорректирован протокол ЛФК с учетом гипертонуса.', $payload->encrypted_human_review_notes);

        $encryptor = app(MedicalEncryptorInterface::class);
        $decryptedNotes = $encryptor->decryptField($this->organization->id, $payload->encrypted_human_review_notes, $payload->encryption_key_version);
        $decryptedOutput = $encryptor->decryptField($this->organization->id, $payload->encrypted_human_edited_output, $payload->encryption_key_version);

        $this->assertSame('Скорректирован протокол ЛФК с учетом гипертонуса.', $decryptedNotes);
        $this->assertSame('Итоговый подтвержденный специалистом протокол', $decryptedOutput);

        $audit = AuditEvent::query()->where('action', 'ai.human_review.submitted')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('гипертонуса', json_encode($audit->metadata));
    }

    public function test_arbitrary_clinical_text_is_rejected_as_reason_code_before_audit_or_review_persistence(): void
    {
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClinicalSynthesizer,
            'workflow_key' => 'invalid_reason_code_test',
            'origin' => AiRunOrigin::User,
            'status' => AiRunStatus::Succeeded,
            'human_review_status' => HumanReviewStatus::PendingReview,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        AiRunPayload::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'encryption_key_version' => 1,
        ]);

        try {
            app(ReviewAiRun::class)->handle(
                actor: $this->specialistUser,
                runId: $run->id,
                decision: HumanReviewDecision::Rejected,
                safeReasonCode: 'patient has severe lumbar pain and needs urgent care',
            );
            $this->fail('Expected arbitrary review reason text to be rejected.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseCount('ai_run_human_reviews', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_a_second_contradictory_review_is_rejected_without_persisting_another_decision(): void
    {
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClinicalSynthesizer,
            'workflow_key' => 'single_review_decision_test',
            'origin' => AiRunOrigin::User,
            'status' => AiRunStatus::Succeeded,
            'human_review_status' => HumanReviewStatus::PendingReview,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        app(ReviewAiRun::class)->handle(
            actor: $this->specialistUser,
            runId: $run->getKey(),
            decision: HumanReviewDecision::Accepted,
        );

        $this->expectException(ValidationException::class);
        app(ReviewAiRun::class)->handle(
            actor: $this->specialistUser,
            runId: $run->getKey(),
            decision: HumanReviewDecision::Rejected,
            safeReasonCode: 'specialist_rejected',
        );

        $this->assertDatabaseCount('ai_run_human_reviews', 1);
    }

    public function test_session_draft_acceptance_reviews_and_updates_only_the_selected_session_field(): void
    {
        $client = Client::factory()->forOrganization($this->organization)->create();
        $specialist = Specialist::factory()->forOrganization($this->organization)->create();
        $created = app(CreateSession::class)->handle($this->specialistUser, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-11-18 10:00:00', 'UTC'),
            pain: 'Исходная жалоба',
            rootCauseHypothesis: 'Исходная гипотеза',
            result: 'Исходный результат',
        ));
        $session = MedicalSession::query()->whereKey($created->id)->firstOrFail();
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClinicalSynthesizer,
            'workflow_key' => ClinicalSynthesizerWorkflow::SessionDraft->value,
            'origin' => AiRunOrigin::User,
            'execution_mode' => AiExecutionMode::Async,
            'client_id' => $client->id,
            'status' => AiRunStatus::Succeeded,
            'human_review_status' => HumanReviewStatus::PendingReview,
            'input_references' => [
                ['type' => 'client', 'id' => $client->id],
                ['type' => 'medical_session', 'id' => $session->id],
            ],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        AiRunPayload::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'encryption_key_version' => 1,
            'encrypted_output_text' => app(MedicalEncryptorInterface::class)->encryptField(
                $this->organization->id,
                'Фактический черновик AI',
                1,
            ),
        ]);

        $review = app(\App\Modules\Sessions\Application\AcceptClinicalSessionAiDraft::class)->handle(
            actor: $this->specialistUser,
            session: $session,
            client: $client,
            runId: (int) $run->getKey(),
            target: ClinicalSessionAiDraftTarget::Result,
            editedOutput: 'Исправленный результат специалиста',
            expectedSnapshot: app(MedicalSessionSnapshotHasher::class)->forSession($session),
        );

        self::assertSame(HumanReviewDecision::EditedAndAccepted, $review->decision);
        self::assertSame('result', $review->target_field);
        self::assertSame(HumanReviewStatus::EditedAndAccepted, $run->fresh()->human_review_status);
        $decrypted = app(GetSession::class)->handle($this->specialistUser, $session->fresh(), $client);
        self::assertSame('Исправленный результат специалиста', $decrypted?->result);
        self::assertSame('Исходная гипотеза', $decrypted?->rootCauseHypothesis);
        self::assertSame('Фактический черновик AI', app(MedicalEncryptorInterface::class)->decryptField(
            $this->organization->id,
            AiRunPayload::query()->where('ai_run_id', $run->id)->firstOrFail()->encrypted_output_text,
            1,
        ));
    }

    public function test_session_draft_rejection_keeps_the_session_unchanged_and_preserves_original_output(): void
    {
        $client = Client::factory()->forOrganization($this->organization)->create();
        $specialist = Specialist::factory()->forOrganization($this->organization)->create();
        $created = app(CreateSession::class)->handle($this->specialistUser, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-11-18 10:00:00', 'UTC'),
            result: 'Исходный результат',
        ));
        $session = MedicalSession::query()->whereKey($created->id)->firstOrFail();
        $run = $this->sessionDraftRun($client, $session, 'Черновик для отклонения');

        app(RejectClinicalSessionAiDraft::class)->handle(
            actor: $this->specialistUser,
            session: $session,
            client: $client,
            runId: (int) $run->getKey(),
            reasonCode: HumanReviewReasonCode::IncorrectContent->value,
        );

        self::assertSame(HumanReviewStatus::Rejected, $run->fresh()->human_review_status);
        self::assertSame(HumanReviewDecision::Rejected, $run->humanReviews()->firstOrFail()->decision);
        self::assertNull($run->humanReviews()->firstOrFail()->target_field);
        self::assertSame('Исходный результат', app(GetSession::class)->handle(
            $this->specialistUser,
            $session->fresh(),
            $client,
        )?->result);
        self::assertSame('Черновик для отклонения', app(MedicalEncryptorInterface::class)->decryptField(
            $this->organization->id,
            AiRunPayload::query()->where('ai_run_id', $run->id)->firstOrFail()->encrypted_output_text,
            1,
        ));
    }

    public function test_session_draft_acceptance_rejects_stale_snapshot_without_review_or_ai_field_write(): void
    {
        $client = Client::factory()->forOrganization($this->organization)->create();
        $specialist = Specialist::factory()->forOrganization($this->organization)->create();
        $created = app(CreateSession::class)->handle($this->specialistUser, $client, new CreateSessionCommand(
            specialistId: (int) $specialist->getKey(),
            occurredAt: Carbon::parse('2026-11-18 10:00:00', 'UTC'),
            result: 'Исходный результат',
        ));
        $session = MedicalSession::query()->whereKey($created->id)->firstOrFail();
        $run = $this->sessionDraftRun($client, $session, 'Черновик при конфликте');
        $staleSnapshot = app(MedicalSessionSnapshotHasher::class)->forSession($session);

        app(UpdateSession::class)->handle(
            actor: $this->specialistUser,
            session: $session,
            command: new UpdateSessionCommand(
                pain: null,
                tests: null,
                observations: null,
                rootCauseHypothesis: null,
                protocol: null,
                result: 'Изменение из другой вкладки',
            ),
            expectedClient: $client,
        );

        try {
            app(\App\Modules\Sessions\Application\AcceptClinicalSessionAiDraft::class)->handle(
                actor: $this->specialistUser,
                session: $session,
                client: $client,
                runId: (int) $run->getKey(),
                target: ClinicalSessionAiDraftTarget::Result,
                editedOutput: 'Не должно быть записано',
                expectedSnapshot: $staleSnapshot,
            );
            self::fail('Expected a stale session snapshot to prevent AI promotion.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        self::assertSame(HumanReviewStatus::PendingReview, $run->fresh()->human_review_status);
        self::assertDatabaseCount('ai_run_human_reviews', 0);
        self::assertSame('Изменение из другой вкладки', app(GetSession::class)->handle(
            $this->specialistUser,
            $session->fresh(),
            $client,
        )?->result);
    }

    private function sessionDraftRun(Client $client, MedicalSession $session, string $output): AiRun
    {
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClinicalSynthesizer,
            'workflow_key' => ClinicalSynthesizerWorkflow::SessionDraft->value,
            'origin' => AiRunOrigin::User,
            'execution_mode' => AiExecutionMode::Async,
            'client_id' => $client->id,
            'status' => AiRunStatus::Succeeded,
            'human_review_status' => HumanReviewStatus::PendingReview,
            'input_references' => [
                ['type' => 'client', 'id' => $client->id],
                ['type' => 'medical_session', 'id' => $session->id],
            ],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        AiRunPayload::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'encryption_key_version' => 1,
            'encrypted_output_text' => app(MedicalEncryptorInterface::class)->encryptField(
                $this->organization->id,
                $output,
                1,
            ),
        ]);

        return $run;
    }
}
