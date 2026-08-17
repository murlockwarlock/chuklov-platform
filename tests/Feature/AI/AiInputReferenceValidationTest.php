<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\DispatchAsyncAiRun;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Validation\AiInputReferenceValidator;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\Attachments\Domain\Enums\AttachmentScanStatus;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceStatus;
use App\Modules\Knowledge\Domain\Enums\KnowledgeSourceType;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

final class AiInputReferenceValidationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizationA;

    private Organization $organizationB;

    private User $userA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organizationA = Organization::factory()->create();
        $this->organizationB = Organization::factory()->create();
        $this->userA = User::factory()->forOrganization($this->organizationA, OrganizationRole::Administrator)->create();

        config()->set('tenancy.default_organization_id', $this->organizationA->id);
        app(OrganizationContext::class)->set($this->organizationA);
    }

    public function test_every_supported_reference_type_is_validated_for_existence_ownership_and_client_context(): void
    {
        $owned = $this->createReferences($this->organizationA, $this->userA);
        $foreign = $this->createReferences($this->organizationB, User::factory()->forOrganization($this->organizationB, OrganizationRole::Administrator)->create());
        $validator = app(AiInputReferenceValidator::class);

        $cases = [
            'client' => [AiCapability::ClinicalSynthesizer, $owned['client']->id, $owned['client']->id],
            'medical_session' => [AiCapability::ClinicalSynthesizer, $owned['medical_session']->id, $owned['client']->id],
            'medical_attachment' => [AiCapability::ClinicalSynthesizer, $owned['medical_attachment']->id, $owned['client']->id],
            'survey_attempt' => [AiCapability::ClinicalSynthesizer, $owned['survey_attempt']->id, $owned['client']->id],
            'booking' => [AiCapability::GeneralAssistant, $owned['booking']->id, $owned['client']->id],
            'knowledge_source' => [AiCapability::ClinicalSynthesizer, $owned['knowledge_source']->id, null],
        ];

        foreach ($cases as $type => [$capability, $id, $clientId]) {
            $validator->validate(
                organizationId: $this->organizationA->id,
                capability: $capability,
                references: [new AiInputReference($type, $id)],
                clientId: $clientId,
            );

            $this->assertValidationFails(fn () => $validator->validate(
                organizationId: $this->organizationA->id,
                capability: $capability,
                references: [new AiInputReference($type, $foreign[$type]->id)],
                clientId: $clientId,
            ));

            $this->assertValidationFails(fn () => $validator->validate(
                organizationId: $this->organizationA->id,
                capability: $capability,
                references: [new AiInputReference($type, 99999999)],
                clientId: $clientId,
            ));
        }
    }

    public function test_async_creation_uses_the_same_reference_boundary_before_persistence(): void
    {
        Queue::fake();
        $foreignClient = Client::factory()->forOrganization($this->organizationB)->create();

        try {
            app(DispatchAsyncAiRun::class)->handle($this->userA, new AiRunRequest(
                capability: AiCapability::ClientCompanion,
                workflowKey: 'foreign_reference_async',
                inputReferences: [new AiInputReference('client', $foreignClient->id)],
            ));
            $this->fail('Expected asynchronous input reference validation to fail.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, AiRun::query()->where('organization_id', $this->organizationA->id)->count());
        $this->assertSame(0, AiRun::query()->where('status', AiRunStatus::Queued)->count());
    }

    public function test_persisted_input_references_contain_only_typed_safe_metadata(): void
    {
        Queue::fake();
        $client = Client::factory()->forOrganization($this->organizationA)->create();

        $run = app(DispatchAsyncAiRun::class)->handle($this->userA, new AiRunRequest(
            capability: AiCapability::ClientCompanion,
            workflowKey: 'safe_reference_metadata',
            inputReferences: [new AiInputReference('client', $client->id)],
        ));

        $this->assertSame([
            ['type' => 'client', 'id' => $client->id],
        ], $run->input_references);
        $this->assertSame($this->userA->id, $run->initiated_by_user_id);
        $this->assertArrayNotHasKey('description', $run->input_references[0]);
    }

    /** @return array<string, object> */
    private function createReferences(Organization $organization, User $user): array
    {
        $client = Client::factory()->forOrganization($organization)->create();
        $specialist = Specialist::factory()->forOrganization($organization)->create();
        $service = Service::factory()->forOrganization($organization)->create();
        $booking = Booking::factory()
            ->forClient($client)
            ->forSpecialist($specialist)
            ->forService($service)
            ->create();
        $session = new MedicalSession;
        $session->forceFill([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'specialist_id' => $specialist->id,
            'encryption_key_version' => 1,
            'occurred_at' => Carbon::now(),
        ]);
        $session->save();
        $attachment = MedicalAttachment::create([
            'uuid' => fake()->uuid(),
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'uploaded_by_user_id' => $user->id,
            'attachment_type' => AttachmentType::MedicalReport,
            'disk' => 'private',
            'storage_path' => 'medical/test.txt',
            'original_filename' => 'test.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'sha256_checksum' => hash('sha256', 'test'),
            'scan_status' => AttachmentScanStatus::Cleared,
            'scanned_at' => Carbon::now(),
        ]);
        $definition = SurveyDefinition::create([
            'organization_id' => $organization->id,
            'definition_key' => 'ai-reference-'.str()->random(8),
            'title' => 'AI reference survey',
            'is_available' => true,
        ]);
        $version = SurveyVersion::create([
            'organization_id' => $organization->id,
            'survey_definition_id' => $definition->id,
            'version' => 1,
            'status' => 'published',
            'title' => 'AI reference survey v1',
            'definition' => ['fields' => []],
            'scoring' => [],
            'published_at' => Carbon::now(),
        ]);
        $attempt = SurveyAttempt::create([
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'survey_definition_id' => $definition->id,
            'survey_version_id' => $version->id,
            'status' => SurveyAttemptStatus::Completed,
            'definition_snapshot' => ['fields' => []],
            'answers_snapshot' => [],
            'scoring_snapshot' => [],
            'result_snapshot' => [],
            'started_at' => Carbon::now()->subMinute(),
            'completed_at' => Carbon::now(),
        ]);
        $source = KnowledgeSource::create([
            'organization_id' => $organization->id,
            'type' => KnowledgeSourceType::AuthoredText,
            'title' => 'AI reference knowledge',
            'status' => KnowledgeSourceStatus::Active,
        ]);

        return [
            'client' => $client,
            'medical_session' => $session,
            'medical_attachment' => $attachment,
            'survey_attempt' => $attempt,
            'booking' => $booking,
            'knowledge_source' => $source,
        ];
    }

    private function assertValidationFails(\Closure $validation): void
    {
        try {
            $validation();
            $this->fail('Expected input reference validation to fail.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
    }
}
