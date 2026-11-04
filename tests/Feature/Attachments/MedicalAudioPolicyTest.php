<?php

namespace Tests\Feature\Attachments;

use App\Models\User;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\MedicalAudioPolicy;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\SetOrganizationSetting;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Enums\OrganizationSettingKey;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MedicalAudioPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_audio_policy_is_organization_scoped_and_rejects_disallowed_format_before_storage(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        app(OrganizationContext::class)->set($organization);

        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::MedicalAudioAllowedMimeTypes,
            json_encode(['audio/x-wav'], JSON_THROW_ON_ERROR),
        );
        app(SetOrganizationSetting::class)->handle(
            $admin,
            OrganizationSettingKey::MedicalAudioBusinessMaxBytes,
            1_048_576,
        );

        $policy = app(MedicalAudioPolicy::class);
        self::assertSame(['audio/x-wav'], $policy->mimeTypes((int) $organization->getKey()));
        self::assertSame(1_048_576, $policy->maxBytes((int) $organization->getKey()));

        $this->expectException(ValidationException::class);
        app(UploadMedicalAttachment::class)->handle(
            $admin,
            new AttachmentUploadCommand(
                file: UploadedFile::fake()->create('voice.mp3', 10, 'audio/mpeg'),
                clientId: (int) $client->getKey(),
                attachmentType: AttachmentType::AudioRecording,
            ),
        );

        self::assertDatabaseCount('medical_attachments', 0);
    }

    public function test_audio_policy_exposes_human_labels_and_safe_defaults(): void
    {
        [$organization] = $this->fixture();
        $policy = app(MedicalAudioPolicy::class);

        self::assertSame(67_108_864, $policy->maxBytes((int) $organization->getKey()));
        self::assertSame('MP3', $policy->labels()['audio/mpeg']);
        self::assertContains('audio/webm', $policy->mimeTypes((int) $organization->getKey()));
    }

    /** @return array{0: Organization, 1: User, 2: Client} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $organization->featureFlags()->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();

        return [$organization, $admin, $client];
    }
}
