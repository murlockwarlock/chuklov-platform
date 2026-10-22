<?php

namespace Tests\Integration;

use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

final class MedicalAttachmentLimitPostgresTest extends TestCase
{
    use DatabaseTruncation;

    public function test_postgresql_client_lock_keeps_parallel_medical_uploads_within_five_file_limit(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The medical attachment limit race requires PostgreSQL row locks.');
        }

        $organization = Organization::factory()->create();
        $organization->featureFlags()->create(['feature_key' => OrganizationFeature::ClientRecords->value, 'enabled' => true]);
        app(OrganizationContext::class)->set($organization);
        OrganizationFeatureGate::invalidate((int) $organization->getKey(), OrganizationFeature::ClientRecords);
        $client = Client::factory()->forOrganization($organization)->create();
        $organizationId = (int) $organization->getKey();
        $clientId = (int) $client->getKey();
        $filePath = tempnam(sys_get_temp_dir(), 'medical-limit-');
        if ($filePath === false) {
            throw new RuntimeException('Unable to create a temporary medical test file.');
        }
        file_put_contents($filePath, "%PDF-1.4\n1 0 obj\n(Synthetic report)\nendobj\n%%EOF\n");

        try {
            for ($attempt = 0; $attempt < 4; $attempt++) {
                $this->upload($client, $filePath, 'medical-limit-seed-'.$attempt);
            }

            $results = Concurrency::driver('process')->run([
                static fn (): string => self::uploadInProcess($organizationId, $clientId, $filePath, 'medical-limit-race-a'),
                static fn (): string => self::uploadInProcess($organizationId, $clientId, $filePath, 'medical-limit-race-b'),
            ]);

            self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'uploaded')));
            self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'limit')));
            self::assertSame(5, MedicalAttachment::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('attachment_type', 'medical_report')
                ->count());
            self::assertCount(5, Storage::disk('private')->allFiles("medical/attachments/{$organizationId}"));
            self::assertSame(5, AuditEvent::query()->where('organization_id', $organizationId)->where('action', 'attachment.uploaded')->count());
        } finally {
            foreach (MedicalAttachment::query()->where('client_id', $client->getKey())->get() as $attachment) {
                Storage::disk($attachment->disk)->delete($attachment->storage_path);
            }
            Storage::disk('private')->deleteDirectory("medical/attachments/{$organizationId}");
            unlink($filePath);
        }
    }

    private function upload(Client $client, string $filePath, string $key): void
    {
        app(UploadMedicalAttachment::class)->handle($client, new AttachmentUploadCommand(
            file: new UploadedFile($filePath, 'synthetic-report.pdf', 'application/pdf', UPLOAD_ERR_OK, true),
            clientId: (int) $client->getKey(),
            uploadKey: $key,
        ));
    }

    private static function uploadInProcess(int $organizationId, int $clientId, string $filePath, string $key): string
    {
        $organization = Organization::query()->findOrFail($organizationId);
        config()->set('tenancy.default_organization_id', $organizationId);
        app(OrganizationContext::class)->set($organization);
        OrganizationFeatureGate::invalidate($organizationId, OrganizationFeature::ClientRecords);
        $client = Client::query()->findOrFail($clientId);

        try {
            app(UploadMedicalAttachment::class)->handle($client, new AttachmentUploadCommand(
                file: new UploadedFile($filePath, 'synthetic-report.pdf', 'application/pdf', UPLOAD_ERR_OK, true),
                clientId: $clientId,
                uploadKey: $key,
            ));

            return 'uploaded';
        } catch (ValidationException $exception) {
            return array_key_exists('file_count', $exception->errors()) ? 'limit' : 'unexpected_validation';
        }
    }
}
