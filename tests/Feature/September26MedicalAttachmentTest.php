<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Attachments\Application\ListClientAttachments;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class September26MedicalAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_upload_persists_privately_and_replay_does_not_duplicate_storage_or_audit(): void
    {
        $client = $this->fixture();
        $key = (string) Str::uuid();
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->from(route('portal.medical-profile'))->post(route('portal.medical-attachments.upload'), [
                'upload_key' => $key, 'file' => $this->report(),
            ])->assertSessionHasNoErrors()->assertRedirect(route('portal.medical-profile'));
        }
        $attachment = MedicalAttachment::query()->sole();
        self::assertSame($key, $attachment->uuid);
        self::assertSame($client->id, $attachment->client_id);
        self::assertNull($attachment->uploaded_by_user_id);
        self::assertSame('private', $attachment->disk);
        self::assertCount(1, Storage::disk('private')->allFiles());
        self::assertSame(1, AuditEvent::query()->where('action', 'attachment.uploaded')->count());
        $staff = User::factory()->forOrganization($client->organization)->create();
        self::assertSame($attachment->id, app(ListClientAttachments::class)->query($staff, $client)->sole()->id);
        $materials = $this->get(route('portal.medical-profile'))->assertOk()->viewData('page')['props']['materials'];
        self::assertCount(1, $materials);
        $this->get($materials[0]['downloadUrl'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('portal.medical-attachments.download', ['uuid' => $key]))->assertForbidden();
        $other = Client::factory()->forOrganization($client->organization)->create();
        $this->withSession(['client_portal.client_id' => $other->id]);
        $this->get($materials[0]['downloadUrl'])->assertNotFound();
    }

    public function test_invalid_file_and_reused_key_do_not_replace_or_leave_orphaned_files(): void
    {
        $this->fixture();
        $key = (string) Str::uuid();
        $this->post(route('portal.medical-attachments.upload'), ['upload_key' => $key, 'file' => $this->report()])->assertSessionHasNoErrors();
        $this->post(route('portal.medical-attachments.upload'), [
            'upload_key' => $key, 'file' => $this->report('Different report'),
        ])->assertSessionHasErrors('upload_key');
        $this->post(route('portal.medical-attachments.upload'), [
            'upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('report.exe', 'not a medical report'),
        ])->assertSessionHasErrors('file');
        self::assertSame(1, MedicalAttachment::query()->count());
        self::assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_forged_foreign_upload_key_cannot_claim_another_clients_file(): void
    {
        $client = $this->fixture();
        $key = (string) Str::uuid();
        $this->post(route('portal.medical-attachments.upload'), ['upload_key' => $key, 'file' => $this->report()])->assertSessionHasNoErrors();
        $other = Client::factory()->forOrganization($client->organization)->create();
        $this->withSession(['client_portal.client_id' => $other->id]);
        $this->post(route('portal.medical-attachments.upload'), ['upload_key' => $key, 'file' => $this->report()])->assertSessionHasErrors('upload_key');
        self::assertSame($client->id, MedicalAttachment::query()->sole()->client_id);
        self::assertCount(1, Storage::disk('private')->allFiles());
    }

    private function fixture(): Client
    {
        Storage::fake('private');
        $organization = Organization::factory()->create();
        app(OrganizationContext::class)->set($organization);
        config(['tenancy.default_organization_id' => $organization->id]);
        $organization->featureFlags()->create(['feature_key' => OrganizationFeature::ClientRecords->value, 'enabled' => true]);
        $client = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $client->id]);

        return $client;
    }

    private function report(string $text = 'Synthetic report'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic-report.pdf', "%PDF-1.4\n1 0 obj\n($text)\nendobj\n%%EOF\n");
    }
}
