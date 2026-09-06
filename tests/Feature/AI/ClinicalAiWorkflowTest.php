<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\StartClinicalDocumentAnalysis;
use App\Modules\AI\Application\Actions\StartClinicalSynthesis;
use App\Modules\AI\Application\Actions\StartPostureAnalysis;
use App\Modules\AI\Application\Evaluations\ControlledPostureFixtureRepository;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\AI\Domain\Registry\AiCapabilityRegistry;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class ClinicalAiWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $staff;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $this->organization = Organization::create([
            'name' => 'Clinical AI Test Clinic',
            'slug' => 'clinical-ai-test-clinic',
        ]);
        $this->staff = User::factory()->forOrganization($this->organization, OrganizationRole::Staff)->create();
        $this->client = $this->newClient('Synthetic client');
        config()->set('tenancy.default_organization_id', $this->organization->id);
        app(OrganizationContext::class)->set($this->organization);
        $this->configureModel();
        $this->configurePrompt(AiCapability::ClinicalDocumentExtraction, 'document', '{{document_text}}');
        $this->configurePrompt(AiCapability::PostureAnalysis, 'posture', 'Три изображения осанки.');
        $this->configurePrompt(
            AiCapability::ClinicalSynthesizer,
            'synthesizer',
            '{{client_name}} {{anamnesis}} {{complaints_goals}} {{recent_sessions}} {{agent_one_result}} {{agent_two_result}} {{survey_results}}',
        );
    }

    public function test_document_and_posture_launches_are_explicit_idempotent_and_tenant_scoped(): void
    {
        Queue::fake();
        $document = $this->attachment($this->client, AttachmentType::MedicalReport, 'report.pdf', 'application/pdf');

        $documentAction = app(StartClinicalDocumentAnalysis::class);
        $firstDocumentRun = $documentAction->handle($this->staff, $document);
        $sameDocumentRun = $documentAction->handle($this->staff, $document);

        self::assertSame($firstDocumentRun->id, $sameDocumentRun->id);
        self::assertSame(1, AiRun::query()->where('client_id', $this->client->id)->count());
        self::assertSame(AiRunStatus::Queued, $firstDocumentRun->status);

        $rerun = $documentAction->handle($this->staff, $document, true);
        self::assertNotSame($firstDocumentRun->id, $rerun->id);
        self::assertLessThanOrEqual(120, strlen((string) $rerun->idempotency_key));
        self::assertSame(2, AiRun::query()->where('client_id', $this->client->id)->count());

        $photos = [
            $this->attachment($this->client, AttachmentType::PosturePhoto, 'front.png'),
            $this->attachment($this->client, AttachmentType::PosturePhoto, 'side.png'),
            $this->attachment($this->client, AttachmentType::PosturePhoto, 'back.png'),
        ];
        $postureAction = app(StartPostureAnalysis::class);
        $postureRun = $postureAction->handle($this->staff, $this->client, [
            'front' => $photos[0]->id,
            'side' => $photos[1]->id,
            'back' => $photos[2]->id,
        ]);
        $samePostureRun = $postureAction->handle($this->staff, $this->client, [
            'front' => $photos[0]->id,
            'side' => $photos[1]->id,
            'back' => $photos[2]->id,
        ]);

        self::assertSame($postureRun->id, $samePostureRun->id);
        self::assertSame(
            ['front', 'side', 'back'],
            array_map(
                static fn (array $reference): string => (string) ($reference['role'] ?? ''),
                array_values(array_filter($postureRun->input_references, static fn (array $reference): bool => $reference['type'] === 'medical_attachment')),
            ),
        );

        $otherClient = $this->newClient('Other synthetic client');
        $otherPhoto = $this->attachment($otherClient, AttachmentType::PosturePhoto, 'other.png');
        $this->expectException(AuthorizationException::class);
        $postureAction->handle($this->staff, $this->client, [
            'front' => $photos[0]->id,
            'side' => $photos[1]->id,
            'back' => $otherPhoto->id,
        ]);
    }

    public function test_synthesis_pins_reviewed_results_and_marks_missing_source_data(): void
    {
        Queue::fake();
        $document = $this->attachment($this->client, AttachmentType::MedicalReport, 'report.pdf', 'application/pdf');
        $documentRun = $this->reviewedRun(
            AiCapability::ClinicalDocumentExtraction,
            [new AiInputReference('client', $this->client->id), new AiInputReference('medical_attachment', $document->id)],
            ['exam_type' => 'Synthetic MRI', 'plain_summary' => 'Old document fact'],
        );
        $postureRun = $this->reviewedRun(
            AiCapability::PostureAnalysis,
            [new AiInputReference('client', $this->client->id)],
            [
                'visual_findings' => [['plane' => 'front', 'observations' => ['Old posture observation']]],
                'leading_compensatory_patterns' => [],
                'practitioner_focus' => [],
                'limitations' => [],
            ],
        );

        $synthesisAction = app(StartClinicalSynthesis::class);
        $synthesisRun = $synthesisAction->handle($this->staff, $this->client);
        $payload = AiRunPayload::query()->where('ai_run_id', $synthesisRun->id)->firstOrFail();
        $prompt = app(MedicalEncryptorInterface::class)->decryptField(
            $this->organization->id,
            $payload->encrypted_user_prompt,
            $payload->encryption_key_version,
        );

        self::assertStringContainsString('Old document fact', (string) $prompt);
        self::assertStringContainsString('Old posture observation', (string) $prompt);
        self::assertStringContainsString('9 систем', (string) $prompt);
        self::assertEqualsCanonicalizing(
            [$documentRun->id, $postureRun->id],
            collect($synthesisRun->input_references)
                ->filter(static fn (array $reference): bool => $reference['type'] === 'ai_run')
                ->pluck('id')
                ->all(),
        );

        $synthesisRun->update(['status' => AiRunStatus::Failed]);
        $retrySynthesisRun = $synthesisAction->handle($this->staff, $this->client);
        self::assertNotSame($synthesisRun->id, $retrySynthesisRun->id);
        self::assertLessThanOrEqual(120, strlen((string) $retrySynthesisRun->idempotency_key));

        $documentPayload = AiRunPayload::query()->where('ai_run_id', $documentRun->id)->firstOrFail();
        $documentPayload->update([
            'encrypted_output_payload' => app(MedicalEncryptorInterface::class)->encryptField(
                $this->organization->id,
                json_encode(['plain_summary' => 'New document fact'], JSON_THROW_ON_ERROR),
                $documentPayload->encryption_key_version,
            ),
        ]);

        $newSynthesisRun = $synthesisAction->handle($this->staff, $this->client);
        self::assertNotSame($synthesisRun->id, $newSynthesisRun->id);
        $refreshedPayload = AiRunPayload::query()->where('ai_run_id', $synthesisRun->id)->firstOrFail();
        $originalPrompt = app(MedicalEncryptorInterface::class)->decryptField(
            $this->organization->id,
            $refreshedPayload->encrypted_user_prompt,
            $refreshedPayload->encryption_key_version,
        );
        self::assertStringContainsString('Old document fact', (string) $originalPrompt);
        self::assertStringNotContainsString('New document fact', (string) $originalPrompt);
    }

    public function test_production_posture_launch_cannot_select_controlled_evaluation_attachments(): void
    {
        $fixture = app(ControlledPostureFixtureRepository::class)->ensure(
            organization: $this->organization,
            actor: $this->staff,
            key: 'ai-eval:production-selector-boundary',
        );

        $this->expectException(InvalidArgumentException::class);
        app(StartPostureAnalysis::class)->handle(
            actor: $this->staff,
            client: Client::query()->findOrFail($fixture['client_id']),
            attachmentIds: [
                'front' => $fixture['references'][0]->id,
                'side' => $fixture['references'][1]->id,
                'back' => $fixture['references'][2]->id,
            ],
        );
    }

    public function test_document_launch_rejects_an_attachment_from_another_organization(): void
    {
        $foreignOrganization = Organization::create([
            'name' => 'Foreign Clinical AI Clinic',
            'slug' => 'foreign-clinical-ai-clinic',
        ]);
        $foreignStaff = User::factory()->forOrganization($foreignOrganization, OrganizationRole::Staff)->create();
        $foreignClient = $this->newClient('Foreign synthetic client', $foreignOrganization);
        $foreignAttachment = $this->attachment(
            client: $foreignClient,
            type: AttachmentType::MedicalReport,
            filename: 'foreign-report.pdf',
            mime: 'application/pdf',
            organization: $foreignOrganization,
            uploader: $foreignStaff,
        );

        $this->expectException(AuthorizationException::class);
        app(StartClinicalDocumentAnalysis::class)->handle($this->staff, $foreignAttachment);
    }

    public function test_posture_launch_rejects_a_client_from_another_organization(): void
    {
        $foreignOrganization = Organization::create([
            'name' => 'Foreign Posture Clinic',
            'slug' => 'foreign-posture-clinic',
        ]);
        $foreignClient = $this->newClient('Foreign posture client', $foreignOrganization);

        $this->expectException(AuthorizationException::class);
        app(StartPostureAnalysis::class)->handle($this->staff, $foreignClient, [
            'front' => 1,
            'side' => 2,
            'back' => 3,
        ]);
    }

    private function configureModel(): void
    {
        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'Clinical AI test provider',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->forceFill([
            'organization_id' => $this->organization->id,
            'credentials' => ['api_key' => 'test-key'],
        ]);
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_name' => 'openai',
            'display_name' => 'OpenAI test provider',
            'is_enabled' => true,
            'health_status' => 'healthy',
            'credential_id' => $credential->id,
            'tested_credential_revision' => $credential->revision_id,
            'tested_configuration_digest' => AiProviderExecutionConfiguration::digest('openai'),
        ]);
        $pricing = new AiPricingSnapshot('USD', 15, 60);
        $capabilities = [
            AiCapability::ClinicalDocumentExtraction->value,
            AiCapability::PostureAnalysis->value,
            AiCapability::ClinicalSynthesizer->value,
            AiModelModality::ImageInput->value,
            AiModelModality::DocumentInput->value,
        ];
        $model = AiModelConfiguration::create([
            'organization_id' => $this->organization->id,
            'provider_config_id' => $provider->id,
            'model_name' => 'test-clinical-model',
            'display_name' => 'Test clinical model',
            'is_enabled' => true,
            'capabilities' => $capabilities,
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => 1,
        ]);
        $release = AiModelRelease::create([
            'organization_id' => $this->organization->id,
            'model_config_id' => $model->id,
            'release_number' => 1,
            'status' => 'active',
            'provider_name' => 'openai',
            'model_name' => 'test-clinical-model',
            'capabilities' => $capabilities,
            'pricing_snapshot' => $pricing->toArray(),
            'activated_at' => Carbon::now(),
        ]);
        $model->update(['active_release_id' => $release->id]);
    }

    private function configurePrompt(AiCapability $capability, string $key, string $template): void
    {
        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => $key,
            'name' => $key,
            'capability' => $capability,
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $this->organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Use only source facts. Return structured output.',
            'user_prompt_template' => $template,
            'output_schema' => AiCapabilityRegistry::get($capability)->defaultOutputSchema,
            'context_policy' => $capability === AiCapability::ClinicalSynthesizer
                ? ['include_client_profile' => true, 'include_medical_summary' => true, 'include_recent_sessions_count' => 5]
                : [],
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->id]);
    }

    private function newClient(string $name, ?Organization $organization = null): Client
    {
        $organization ??= $this->organization;
        $client = new Client;
        $client->forceFill([
            'organization_id' => $organization->id,
            'full_name' => $name,
            'language' => 'ru',
            'timezone' => 'UTC',
        ]);
        $client->save();

        return $client;
    }

    private function attachment(
        Client $client,
        AttachmentType $type,
        string $filename,
        string $mime = 'image/png',
        ?Organization $organization = null,
        ?User $uploader = null,
    ): MedicalAttachment {
        $organization ??= $this->organization;
        $uploader ??= $this->staff;
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        self::assertIsString($bytes);
        $uuid = (string) Str::uuid();
        $path = 'medical/attachments/'.$organization->id.'/'.$uuid.'.'.($mime === 'application/pdf' ? 'pdf' : 'png');
        Storage::disk('private')->put($path, $bytes);
        $attachment = new MedicalAttachment;
        $attachment->forceFill([
            'uuid' => $uuid,
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'uploaded_by_user_id' => $uploader->id,
            'attachment_type' => $type,
            'disk' => 'private',
            'storage_path' => $path,
            'original_filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => strlen($bytes),
            'sha256_checksum' => hash('sha256', $bytes),
        ]);
        $attachment->save();

        return $attachment;
    }

    /**
     * @param  list<AiInputReference>  $references
     * @param  array<string, mixed>  $payload
     */
    private function reviewedRun(AiCapability $capability, array $references, array $payload): AiRun
    {
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => $capability,
            'workflow_key' => $capability->value,
            'origin' => AiRunOrigin::User,
            'execution_mode' => AiExecutionMode::Async,
            'client_id' => $this->client->id,
            'status' => AiRunStatus::Succeeded,
            'human_review_status' => HumanReviewStatus::Accepted,
            'input_references' => array_map(static fn (AiInputReference $reference): array => $reference->toArray(), $references),
            'context_provenance' => [],
            'token_usage' => [],
        ]);
        $encryptor = app(MedicalEncryptorInterface::class);
        AiRunPayload::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'encryption_key_version' => 1,
            'encrypted_output_payload' => $encryptor->encryptField($this->organization->id, json_encode($payload, JSON_THROW_ON_ERROR), 1),
            'encrypted_output_text' => $encryptor->encryptField($this->organization->id, json_encode($payload, JSON_THROW_ON_ERROR), 1),
        ]);

        return $run;
    }
}
