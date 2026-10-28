<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Attachments\Application\ListClientAttachments;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\RecordAuditEvent;
use App\Modules\Security\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
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
        $uploadAudit = AuditEvent::query()->where('action', 'attachment.uploaded')->sole();
        self::assertSame('client', $uploadAudit->metadata['actor_type']);
        self::assertSame($client->id, $uploadAudit->metadata['actor_client_id']);
        $staff = User::factory()->forOrganization($client->organization)->create();
        self::assertSame($attachment->id, app(ListClientAttachments::class)->query($staff, $client)->sole()->id);
        $materials = $this->get(route('portal.medical-profile'))->assertOk()->viewData('page')['props']['materials'];
        self::assertCount(1, $materials);
        $this->get($materials[0]['downloadUrl'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $downloadAudit = AuditEvent::query()->where('action', 'attachment.downloaded')->sole();
        self::assertSame($attachment->id, (int) $downloadAudit->target_id);
        self::assertSame(['source' => 'portal', 'actor_type' => 'client', 'actor_client_id' => $client->id], $downloadAudit->metadata);
        $this->get(route('portal.medical-attachments.download', ['uuid' => $key]))->assertForbidden();
        $other = Client::factory()->forOrganization($client->organization)->create();
        $this->withSession(['client_portal.client_id' => $other->id]);
        Log::spy();
        $this->get($materials[0]['downloadUrl'])->assertNotFound();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $event, array $context): bool => $event === 'portal.medical_attachment.access_denied'
            && $context['organization_id'] === $client->organization_id
            && $context['actor_client_id'] === $other->id
            && $context['entity_id'] === $key
            && $context['safe_error_code'] === 'attachment_not_found_or_unavailable'
            && $context['response_status'] === 404)->once();
    }

    public function test_invalid_file_and_reused_key_do_not_replace_or_leave_orphaned_files(): void
    {
        $this->fixture();
        $key = (string) Str::uuid();
        $this->post(route('portal.medical-attachments.upload'), ['upload_key' => $key, 'file' => $this->report()])->assertSessionHasNoErrors();
        Log::spy();
        $this->post(route('portal.medical-attachments.upload'), [
            'upload_key' => $key, 'file' => $this->report('Different report'),
        ])->assertSessionHasErrors('upload_key');
        $this->post(route('portal.medical-attachments.upload'), [
            'upload_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->createWithContent('report.exe', 'not a medical report'),
        ])->assertSessionHasErrors('file');
        $loggedErrors = [];
        Log::shouldHaveReceived('log')->withArgs(function (string $level, string $event, array $context) use (&$loggedErrors): bool {
            if ($level !== 'warning' || $event !== 'portal.medical_attachment.upload_rejected') {
                return false;
            }

            $loggedErrors[$context['safe_error_code']] = $context;

            return true;
        })->twice();
        self::assertEqualsCanonicalizing(['duplicate_upload_key', 'unsupported_file_type'], array_keys($loggedErrors));
        $serializedLog = json_encode($loggedErrors, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('synthetic-report.pdf', $serializedLog);
        self::assertStringNotContainsString('Different report', $serializedLog);
        self::assertStringNotContainsString('not a medical report', $serializedLog);
        self::assertStringNotContainsString($key, $serializedLog);
        self::assertSame(1, MedicalAttachment::query()->count());
        self::assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_medical_profile_upload_limit_applies_across_batches_and_replays_after_limit(): void
    {
        $client = $this->fixture();
        $keys = [];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $keys[] = (string) Str::uuid();
            $this->from(route('portal.medical-profile'))
                ->post(route('portal.medical-attachments.upload'), ['upload_key' => $keys[$attempt], 'file' => $this->report()])
                ->assertSessionHasNoErrors();
        }

        Log::spy();
        $this->from(route('portal.medical-profile'))
            ->post(route('portal.medical-attachments.upload'), ['upload_key' => (string) Str::uuid(), 'file' => $this->report()])
            ->assertSessionHasErrors('file_count', 'В медицинском профиле можно загрузить не более пяти файлов.');

        self::assertSame(5, MedicalAttachment::query()->where('client_id', $client->getKey())->count());
        self::assertCount(5, Storage::disk('private')->allFiles());
        self::assertSame(5, AuditEvent::query()->where('action', 'attachment.uploaded')->count());
        Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $event, array $context): bool => $level === 'warning'
            && $event === 'portal.medical_attachment.upload_rejected'
            && $context['organization_id'] === $client->organization_id
            && $context['actor_client_id'] === $client->getKey()
            && $context['safe_error_code'] === 'medical_attachment_limit_reached'
            && $context['response_status'] === 422
            && $context['outcome'] === 'rejected')->once();

        $this->from(route('portal.medical-profile'))
            ->post(route('portal.medical-attachments.upload'), ['upload_key' => $keys[4], 'file' => $this->report()])
            ->assertSessionHasNoErrors();

        self::assertSame(5, MedicalAttachment::query()->where('client_id', $client->getKey())->count());
        self::assertCount(5, Storage::disk('private')->allFiles());
        self::assertSame(5, AuditEvent::query()->where('action', 'attachment.uploaded')->count());
    }

    public function test_unauthenticated_medical_attachment_access_returns_a_human_status_and_safe_diagnostic(): void
    {
        $client = $this->fixture();
        $uuid = (string) Str::uuid();
        Log::spy();

        $this->withSession(['client_portal.client_id' => null])
            ->get(route('portal.medical-attachments.download', ['uuid' => $uuid, 'mode' => 'download']).'&expires=1&signature=FAKE_MEDICAL_SIGNATURE')
            ->assertUnauthorized()
            ->assertSeeText('Войдите в личный кабинет и снова откройте медицинские данные.');

        Log::shouldHaveReceived('warning')->withArgs(function (string $event, array $context) use ($client, $uuid): bool {
            $serialized = json_encode($context, JSON_THROW_ON_ERROR);

            return $event === 'portal.medical_attachment.access_denied'
                && $context['operation'] === 'portal.medical-attachments.download'
                && $context['organization_id'] === $client->organization_id
                && $context['actor_client_id'] === null
                && $context['entity_id'] === $uuid
                && $context['safe_error_code'] === 'client_session_required'
                && $context['response_status'] === 401
                && ! str_contains($serialized, 'FAKE_MEDICAL_SIGNATURE');
        })->once();
    }

    public function test_invalid_signed_attachment_link_returns_a_human_error_and_safe_diagnostic(): void
    {
        $client = $this->fixture();
        $uuid = (string) Str::uuid();
        Log::spy();

        $this->get(route('portal.medical-attachments.download', ['uuid' => $uuid, 'mode' => 'download']).'&expires=1&signature=FAKE_MEDICAL_SIGNATURE')
            ->assertForbidden()
            ->assertSeeText('Медицинская ссылка устарела или недействительна. Откройте медицинские данные и попробуйте снова.');

        Log::shouldHaveReceived('warning')->withArgs(function (string $event, array $context) use ($client, $uuid): bool {
            $serialized = json_encode($context, JSON_THROW_ON_ERROR);

            return $event === 'portal.medical_attachment.access_denied'
                && $context['organization_id'] === $client->organization_id
                && $context['actor_client_id'] === $client->id
                && $context['entity_id'] === $uuid
                && $context['safe_error_code'] === 'invalid_or_expired_signature'
                && $context['response_status'] === 403
                && ! str_contains($serialized, 'FAKE_MEDICAL_SIGNATURE');
        })->once();
    }

    public function test_private_storage_failure_returns_a_retryable_status_and_logs_only_safe_context(): void
    {
        $client = $this->fixture();
        $key = (string) Str::uuid();
        $this->post(route('portal.medical-attachments.upload'), ['upload_key' => $key, 'file' => $this->report()])->assertSessionHasNoErrors();
        $attachment = MedicalAttachment::query()->sole();
        $materials = $this->get(route('portal.medical-profile'))->assertOk()->viewData('page')['props']['materials'];
        $storage = Mockery::mock(AttachmentStorageInterface::class);
        $storage->shouldReceive('readStream')->once()->with($attachment->storage_path)->andThrow(new RuntimeException('synthetic private storage path'));
        $this->app->instance(AttachmentStorageInterface::class, $storage);
        Log::spy();

        $this->get($materials[0]['downloadUrl'])
            ->assertStatus(503)
            ->assertSeeText('Не удалось открыть файл. Повторите попытку.')
            ->assertDontSeeText('synthetic private storage path');

        Log::shouldHaveReceived('error')->withArgs(function (string $event, array $context) use ($client, $attachment): bool {
            $serialized = json_encode($context, JSON_THROW_ON_ERROR);

            return $event === 'portal.medical_attachment.download_failed'
                && $context['organization_id'] === $client->organization_id
                && $context['actor_client_id'] === $client->id
                && $context['entity_id'] === $attachment->uuid
                && $context['safe_error_code'] === 'private_storage_read_failed'
                && $context['response_status'] === 503
                && ! str_contains($serialized, $attachment->original_filename)
                && ! str_contains($serialized, $attachment->storage_path)
                && ! str_contains($serialized, 'synthetic private storage path');
        })->once();
        self::assertSame(0, AuditEvent::query()->where('action', 'attachment.downloaded')->count());
    }

    public function test_audit_failure_closes_the_private_stream_and_returns_a_retryable_status(): void
    {
        $client = $this->fixture();
        $key = (string) Str::uuid();
        $this->post(route('portal.medical-attachments.upload'), ['upload_key' => $key, 'file' => $this->report()])->assertSessionHasNoErrors();
        $attachment = MedicalAttachment::query()->sole();
        $materials = $this->get(route('portal.medical-profile'))->assertOk()->viewData('page')['props']['materials'];
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        $storage = Mockery::mock(AttachmentStorageInterface::class);
        $storage->shouldReceive('readStream')->once()->with($attachment->storage_path)->andReturn($stream);
        $audit = Mockery::mock(RecordAuditEvent::class);
        $audit->shouldReceive('handle')->once()->andThrow(new RuntimeException('synthetic audit failure'));
        $this->app->instance(AttachmentStorageInterface::class, $storage);
        $this->app->instance(RecordAuditEvent::class, $audit);
        Log::spy();

        $this->get($materials[0]['downloadUrl'])
            ->assertStatus(503)
            ->assertSeeText('Не удалось открыть файл. Повторите попытку.')
            ->assertDontSeeText('synthetic audit failure');

        self::assertFalse(is_resource($stream));
        Log::shouldHaveReceived('error')->withArgs(fn (string $event, array $context): bool => $event === 'portal.medical_attachment.download_failed'
            && $context['organization_id'] === $client->organization_id
            && $context['actor_client_id'] === $client->id
            && $context['entity_id'] === $attachment->uuid
            && $context['safe_error_code'] === 'download_audit_write_failed'
            && $context['response_status'] === 503)->once();
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
        $client = Client::factory()->forOrganization($organization)->create(['language' => 'ru']);
        $this->withSession(['client_portal.client_id' => $client->id]);

        return $client;
    }

    private function report(string $text = 'Synthetic report'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('synthetic-report.pdf', "%PDF-1.4\n1 0 obj\n($text)\nendobj\n%%EOF\n");
    }
}
