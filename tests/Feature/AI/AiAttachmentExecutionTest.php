<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Attachments\AiAttachmentResolver;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\ValueObjects\AiInputReference;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Files\File;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

final class AiAttachmentExecutionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->organization = Organization::create([
            'name' => 'Attachment Clinic',
            'slug' => 'attachment-clinic',
        ]);
        $this->admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();
        $this->client = Client::factory()->forOrganization($this->organization)->create();
        app(OrganizationContext::class)->set($this->organization);
    }

    public function test_medical_report_is_sent_as_a_private_sdk_document_attachment(): void
    {
        $content = '%PDF-1.7 private clinical conclusion';
        $attachment = $this->attachment(AttachmentType::MedicalReport, 'application/pdf', 'report.pdf', $content);
        $files = app(AiAttachmentResolver::class)->resolve(
            organizationId: $this->organization->id,
            capability: AiCapability::ClinicalDocumentExtraction,
            references: [new AiInputReference('medical_attachment', $attachment->id)],
            actor: $this->admin,
        )['files'];

        $body = $this->sendToOpenAi($files, 'gpt-4o-mini');
        $userMessage = collect($body['input'])->firstWhere('role', 'user');
        $filePart = collect($userMessage['content'])->firstWhere('type', 'input_file');

        $this->assertSame('input_file', $filePart['type']);
        $this->assertStringContainsString(base64_encode($content), $filePart['file_data']);
        $this->assertStringNotContainsString('temporaryUrl', json_encode($body));
        Storage::disk('private')->assertExists($attachment->storage_path);
    }

    public function test_posture_analysis_sends_exactly_three_private_image_attachments(): void
    {
        $attachments = collect([
            $this->attachment(AttachmentType::PosturePhoto, 'image/jpeg', 'left.jpg', 'left-image'),
            $this->attachment(AttachmentType::PosturePhoto, 'image/png', 'front.png', 'front-image'),
            $this->attachment(AttachmentType::PosturePhoto, 'image/webp', 'right.webp', 'right-image'),
        ]);
        $references = $attachments->map(fn (MedicalAttachment $attachment): AiInputReference => new AiInputReference('medical_attachment', $attachment->id))->all();
        $files = app(AiAttachmentResolver::class)->resolve(
            organizationId: $this->organization->id,
            capability: AiCapability::PostureAnalysis,
            references: $references,
            actor: $this->admin,
        )['files'];

        $body = $this->sendToOpenAi($files, 'gpt-4o-mini');
        $userMessage = collect($body['input'])->firstWhere('role', 'user');
        $imageParts = collect($userMessage['content'])->where('type', 'input_image');

        $this->assertCount(3, $files);
        $this->assertCount(3, $imageParts);
    }

    public function test_posture_analysis_rejects_zero_one_two_or_four_photos_before_provider_io(): void
    {
        foreach ([0, 1, 2, 4] as $count) {
            $references = [];
            for ($index = 0; $index < $count; $index++) {
                $attachment = $this->attachment(AttachmentType::PosturePhoto, 'image/jpeg', "photo-{$count}-{$index}.jpg", 'image');
                $references[] = new AiInputReference('medical_attachment', $attachment->id);
            }

            try {
                app(AiAttachmentResolver::class)->resolve(
                    organizationId: $this->organization->id,
                    capability: AiCapability::PostureAnalysis,
                    references: $references,
                    actor: $this->admin,
                );
                $this->fail("Expected posture attachment count {$count} to fail closed.");
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('three', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_cross_organization_attachment_is_not_resolved(): void
    {
        $otherOrganization = Organization::create(['name' => 'Other Clinic', 'slug' => 'other-clinic']);
        $otherAdmin = User::factory()->forOrganization($otherOrganization, OrganizationRole::Administrator)->create();
        $otherClient = Client::factory()->forOrganization($otherOrganization)->create();
        $attachment = $this->attachmentFor(
            organization: $otherOrganization,
            client: $otherClient,
            uploader: $otherAdmin,
            type: AttachmentType::MedicalReport,
            mime: 'application/pdf',
            filename: 'other.pdf',
            content: 'other tenant content',
        );

        $this->expectException(\InvalidArgumentException::class);
        app(AiAttachmentResolver::class)->resolve(
            organizationId: $this->organization->id,
            capability: AiCapability::ClinicalDocumentExtraction,
            references: [new AiInputReference('medical_attachment', $attachment->id)],
            actor: $this->admin,
        );
    }

    public function test_unsupported_mime_or_attachment_type_is_rejected(): void
    {
        $references = [];
        foreach (range(1, 3) as $index) {
            $wrongType = $this->attachment(AttachmentType::MedicalReport, 'application/pdf', "report-{$index}.pdf", 'pdf');
            $references[] = new AiInputReference('medical_attachment', $wrongType->id);
        }

        try {
            app(AiAttachmentResolver::class)->resolve(
                organizationId: $this->organization->id,
                capability: AiCapability::PostureAnalysis,
                references: $references,
                actor: $this->admin,
            );
            $this->fail('Expected a medical report to be rejected by posture analysis.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('posture', strtolower($exception->getMessage()));
        }

        $mimeReferences = [];
        foreach (range(1, 2) as $index) {
            $valid = $this->attachment(AttachmentType::PosturePhoto, 'image/jpeg', "valid-{$index}.jpg", 'image');
            $mimeReferences[] = new AiInputReference('medical_attachment', $valid->id);
        }
        $unsupported = $this->attachment(AttachmentType::PosturePhoto, 'application/octet-stream', 'unknown.bin', 'bytes');
        $mimeReferences[] = new AiInputReference('medical_attachment', $unsupported->id);

        try {
            app(AiAttachmentResolver::class)->resolve(
                organizationId: $this->organization->id,
                capability: AiCapability::PostureAnalysis,
                references: $mimeReferences,
                actor: $this->admin,
            );
            $this->fail('Expected an unsupported attachment MIME to fail closed.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('image', strtolower($exception->getMessage()));
        }
    }

    public function test_attachment_provenance_has_only_safe_immutable_metadata(): void
    {
        $secretClinicalText = 'patient diagnosis must never enter generic metadata';
        $attachment = $this->attachment(AttachmentType::MedicalReport, 'text/plain', 'report.txt', $secretClinicalText);
        $result = app(AiAttachmentResolver::class)->resolve(
            organizationId: $this->organization->id,
            capability: AiCapability::ClinicalDocumentExtraction,
            references: [new AiInputReference('medical_attachment', $attachment->id)],
            actor: $this->admin,
        );
        $metadata = json_encode($result['provenance'], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($secretClinicalText, $metadata);
        $this->assertStringContainsString((string) $attachment->id, $metadata);
        $this->assertStringContainsString($attachment->sha256_checksum, $metadata);
        $this->assertStringContainsString('mime_type', $metadata);
        $this->assertStringContainsString('size_bytes', $metadata);
    }

    /** @param list<File> $files */
    private function sendToOpenAi(array $files, string $model): array
    {
        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'Attachment OpenAI',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $this->organization->id;
        $credential->credentials = ['api_key' => 'attachment-test-key'];
        $credential->status = CredentialStatus::Active;
        $credential->save();
        Http::fake([
            'https://api.openai.com/v1/responses' => function ($request) {
                $this->capturedProviderBody = $request->data();

                return Http::response([
                    'id' => 'attachment_response',
                    'model' => 'gpt-4o-mini',
                    'status' => 'completed',
                    'output' => [[
                        'type' => 'message',
                        'status' => 'completed',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => 'ok',
                            'annotations' => [],
                        ]],
                    ]],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                ]);
            },
        ]);
        $agent = new DynamicWorkflowAgent(instructionsText: 'Process safely.');
        $provider = app(AiProviderFactory::class)->createTextProvider('openai', $credential, $agent);
        $provider->prompt(new AgentPrompt(
            agent: $agent,
            prompt: 'Protected attachment request',
            attachments: $files,
            provider: $provider,
            model: $model,
        ));

        return $this->capturedProviderBody;
    }

    private array $capturedProviderBody = [];

    private function attachment(
        AttachmentType $type,
        string $mime,
        string $filename,
        string $content,
    ): MedicalAttachment {
        return $this->attachmentFor($this->organization, $this->client, $this->admin, $type, $mime, $filename, $content);
    }

    private function attachmentFor(
        Organization $organization,
        Client $client,
        User $uploader,
        AttachmentType $type,
        string $mime,
        string $filename,
        string $content,
    ): MedicalAttachment {
        $uuid = (string) Str::uuid();
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $path = "medical/attachments/{$organization->id}/{$uuid}.{$extension}";
        Storage::disk('private')->put($path, $content);

        return MedicalAttachment::create([
            'uuid' => $uuid,
            'organization_id' => $organization->id,
            'client_id' => $client->id,
            'uploaded_by_user_id' => $uploader->id,
            'attachment_type' => $type,
            'disk' => 'private',
            'storage_path' => $path,
            'original_filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => strlen($content),
            'sha256_checksum' => hash('sha256', $content),
        ]);
    }
}
