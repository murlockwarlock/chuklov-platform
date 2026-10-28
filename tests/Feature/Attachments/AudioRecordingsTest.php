<?php

namespace Tests\Feature\Attachments;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ClientAudioRecordingsRelationManager;
use App\Models\User;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\ListClientMedicalMaterials;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class AudioRecordingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_master_can_upload_audio_and_the_file_stays_private(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $audio = app(UploadMedicalAttachment::class)->handle(
            $admin,
            new AttachmentUploadCommand(
                file: $this->wavUpload('session.wav'),
                clientId: (int) $client->getKey(),
                attachmentType: AttachmentType::AudioRecording,
            ),
        );

        self::assertSame(AttachmentType::AudioRecording, $audio->attachment_type);
        self::assertSame('audio/x-wav', $audio->mime_type);
        Storage::disk('private')->assertExists($audio->storage_path);
        self::assertDatabaseHas('medical_attachments', [
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'attachment_type' => AttachmentType::AudioRecording->value,
        ]);
    }

    public function test_audio_relation_manager_shows_play_download_and_delete_without_portal_materials(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $audio = $this->audioAttachment($organization, $admin, $client);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($admin)->test(ClientAudioRecordingsRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => ViewClient::class,
        ]);

        $component
            ->assertSuccessful()
            ->assertTableColumnStateSet('original_filename', 'session.wav', $audio)
            ->assertTableActionVisible('play', $audio)
            ->assertTableActionVisible('download', $audio)
            ->assertTableActionVisible('delete', $audio);

        $component->mountTableAction('play', $audio);
        self::assertStringContainsString('<audio', $component->getMountedActionModalHtml());

        $component->unmountTableAction();
        $component->mountTableAction('delete', $audio);
        $component->callMountedTableAction();

        self::assertDatabaseMissing('medical_attachments', ['id' => $audio->getKey()]);
        Storage::disk('private')->assertMissing($audio->storage_path);
    }

    public function test_audio_is_not_exposed_to_client_materials(): void
    {
        [$organization, $admin, $client] = $this->fixture();
        $audio = $this->audioAttachment($organization, $admin, $client);
        app(ClientPortalContext::class)->set($client);

        $materials = app(ListClientMedicalMaterials::class)->handle();

        self::assertFalse(collect($materials)->contains('id', (int) $audio->getKey()));
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
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }

    private function audioAttachment(Organization $organization, User $admin, Client $client): MedicalAttachment
    {
        $uuid = (string) Str::uuid();
        $path = "medical/attachments/{$organization->getKey()}/{$uuid}.wav";
        Storage::disk('private')->put($path, $this->wavContent());

        return MedicalAttachment::query()->create([
            'uuid' => $uuid,
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'uploaded_by_user_id' => $admin->getKey(),
            'attachment_type' => AttachmentType::AudioRecording,
            'disk' => 'private',
            'storage_path' => $path,
            'original_filename' => 'session.wav',
            'mime_type' => 'audio/x-wav',
            'size_bytes' => strlen($this->wavContent()),
            'sha256_checksum' => hash('sha256', $this->wavContent()),
        ]);
    }

    private function wavUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->wavContent());
    }

    private function wavContent(): string
    {
        $data = str_repeat("\0", 1600);

        return 'RIFF'.pack('V', 36 + strlen($data))
            .'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }
}
