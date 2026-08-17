<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\ReviewAiRun;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewDecision;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
