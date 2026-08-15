<?php

namespace Tests\Feature\Attachments;

use App\Models\User;
use App\Modules\Attachments\Application\DownloadMedicalAttachment;
use App\Modules\Attachments\Application\DTOs\AttachmentUploadCommand;
use App\Modules\Attachments\Application\GetTemporaryAttachmentUrl;
use App\Modules\Attachments\Application\UploadMedicalAttachment;
use App\Modules\Attachments\Domain\Contracts\AttachmentScannerInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentScanStatus;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Exceptions\AttachmentNotAvailableException;
use App\Modules\Attachments\Domain\Exceptions\InvalidAttachmentException;
use App\Modules\Attachments\Domain\Exceptions\UnsupportedDicomException;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Attachments\Infrastructure\Scanning\FailClosedAttachmentScanner;
use App\Modules\Attachments\Infrastructure\Scanning\LocalDeterministicAttachmentScanner;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class MedicalAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_default_runtime_scanner_fails_closed_and_quarantines_uploads(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();

        // Ensure default runtime scanner is bound
        $this->app->bind(AttachmentScannerInterface::class, FailClosedAttachmentScanner::class);

        $uploader = app(UploadMedicalAttachment::class);
        $file = $this->fakePdf('runtime_report.pdf');

        $attachment = $uploader->handle($admin, new AttachmentUploadCommand(
            file: $file,
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));

        self::assertSame(AttachmentScanStatus::Quarantined, $attachment->scan_status);
        self::assertFalse($attachment->isAvailable());
        self::assertSame('runtime_fail_closed', $attachment->scan_result_metadata['scanner_name'] ?? null);
        self::assertSame('Антивирусный сканер не настроен на сервере. Файл помещён на карантин.', $attachment->scan_result_metadata['reason'] ?? null);
    }

    public function test_authorized_staff_can_upload_pdf_and_images_with_fake_scanner(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $this->app->bind(AttachmentScannerInterface::class, LocalDeterministicAttachmentScanner::class);

        $uploader = app(UploadMedicalAttachment::class);

        // Upload PDF
        $pdfFile = $this->fakePdf('conclusion.pdf');
        $pdfAttachment = $uploader->handle($admin, new AttachmentUploadCommand(
            file: $pdfFile,
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));

        self::assertInstanceOf(MedicalAttachment::class, $pdfAttachment);
        self::assertSame('conclusion.pdf', $pdfAttachment->original_filename);
        self::assertSame('application/pdf', $pdfAttachment->mime_type);
        self::assertSame('private', $pdfAttachment->disk);
        self::assertSame(AttachmentType::MedicalReport, $pdfAttachment->attachment_type);
        self::assertSame(AttachmentScanStatus::Cleared, $pdfAttachment->scan_status);
        self::assertTrue($pdfAttachment->isAvailable());

        // Verify file was stored on private disk under UUID path
        Storage::disk('private')->assertExists($pdfAttachment->storage_path);
        self::assertStringStartsWith("medical/attachments/{$organization->getKey()}/", $pdfAttachment->storage_path);
        self::assertStringEndsWith('.pdf', $pdfAttachment->storage_path);
        self::assertStringNotContainsString('conclusion.pdf', $pdfAttachment->storage_path);

        // Upload JPEG image (posture photo)
        $jpgFile = UploadedFile::fake()->image('posture_front.jpg', 800, 600);
        $jpgAttachment = $uploader->handle($admin, new AttachmentUploadCommand(
            file: $jpgFile,
            attachmentType: AttachmentType::PosturePhoto,
            clientId: (int) $client->getKey(),
        ));
        self::assertSame('image/jpeg', $jpgAttachment->mime_type);
        self::assertSame(AttachmentType::PosturePhoto, $jpgAttachment->attachment_type);
        Storage::disk('private')->assertExists($jpgAttachment->storage_path);

        // Upload PNG image
        $pngFile = UploadedFile::fake()->image('posture_side.png', 800, 600);
        $pngAttachment = $uploader->handle($admin, new AttachmentUploadCommand(
            file: $pngFile,
            attachmentType: AttachmentType::PosturePhoto,
            clientId: (int) $client->getKey(),
        ));
        self::assertSame('image/png', $pngAttachment->mime_type);
        Storage::disk('private')->assertExists($pngAttachment->storage_path);

        // Upload WebP image
        $webpFile = $this->fakeWebp('posture_back.webp');
        $webpAttachment = $uploader->handle($admin, new AttachmentUploadCommand(
            file: $webpFile,
            attachmentType: AttachmentType::PosturePhoto,
            clientId: (int) $client->getKey(),
        ));
        self::assertSame('image/webp', $webpAttachment->mime_type);
        Storage::disk('private')->assertExists($webpAttachment->storage_path);
    }

    public function test_server_side_mime_sniffing_rejects_extension_spoofs(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $this->app->bind(AttachmentScannerInterface::class, LocalDeterministicAttachmentScanner::class);

        $uploader = app(UploadMedicalAttachment::class);

        // Plain text content disguised with .pdf extension
        $fakePdf = UploadedFile::fake()->createWithContent('malicious.pdf', '<html><body>Fake PDF content</body></html>');

        $this->expectException(InvalidAttachmentException::class);
        $uploader->handle($admin, new AttachmentUploadCommand(
            file: $fakePdf,
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));
    }

    public function test_configurable_file_size_limit_is_enforced(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $this->app->bind(AttachmentScannerInterface::class, LocalDeterministicAttachmentScanner::class);

        // Configure a small limit of 1 KB
        config()->set('medical.attachment_max_bytes', 1024);

        $uploader = app(UploadMedicalAttachment::class);
        $pdfFile = $this->fakePdf('large.pdf'); // ~300 bytes

        // Create a 2 KB file
        $tooLargeFile = UploadedFile::fake()->createWithContent('too_large.pdf', str_repeat('%PDF-1.4 ', 300));

        $this->expectException(InvalidAttachmentException::class);
        $uploader->handle($admin, new AttachmentUploadCommand(
            file: $tooLargeFile,
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));
    }

    public function test_raw_dicom_files_are_strictly_rejected(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $this->app->bind(AttachmentScannerInterface::class, LocalDeterministicAttachmentScanner::class);

        $uploader = app(UploadMedicalAttachment::class);

        // Case 1: .dcm extension
        $dcmFile = UploadedFile::fake()->createWithContent('scan.dcm', 'sample dicom bytes');

        try {
            $uploader->handle($admin, new AttachmentUploadCommand(
                file: $dcmFile,
                attachmentType: AttachmentType::MedicalReport,
                clientId: (int) $client->getKey(),
            ));
            self::fail('Expected UnsupportedDicomException for .dcm file');
        } catch (UnsupportedDicomException $e) {
            self::assertStringContainsString('DICOM', $e->getMessage());
        }

        // Case 2: DICOM magic header in file content (128-byte preamble + 'DICM')
        $dicomContent = str_repeat("\x00", 128).'DICM'.str_repeat("\x00", 100);
        $headerDicomFile = UploadedFile::fake()->createWithContent('hidden_scan.pdf', $dicomContent);

        try {
            $uploader->handle($admin, new AttachmentUploadCommand(
                file: $headerDicomFile,
                attachmentType: AttachmentType::MedicalReport,
                clientId: (int) $client->getKey(),
            ));
            self::fail('Expected UnsupportedDicomException for DICOM magic header');
        } catch (UnsupportedDicomException $e) {
            self::assertStringContainsString('DICOM', $e->getMessage());
        }
    }

    public function test_scanner_and_quarantine_lifecycle_with_fake_scanner(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $this->app->bind(AttachmentScannerInterface::class, LocalDeterministicAttachmentScanner::class);

        $uploader = app(UploadMedicalAttachment::class);

        // 1. Cleared file
        $clearedFile = $this->fakePdf('clean.pdf');
        $clearedAtt = $uploader->handle($admin, new AttachmentUploadCommand(
            file: $clearedFile,
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));
        self::assertSame(AttachmentScanStatus::Cleared, $clearedAtt->scan_status);
        self::assertTrue($clearedAtt->isAvailable());

        // 2. Quarantined file (simulated malware marker)
        $quarantineFile = UploadedFile::fake()->createWithContent(
            'test-trigger-quarantine.txt',
            'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
        );
        $quarantinedAtt = $uploader->handle($admin, new AttachmentUploadCommand(
            file: $quarantineFile,
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));
        self::assertSame(AttachmentScanStatus::Quarantined, $quarantinedAtt->scan_status);
        self::assertFalse($quarantinedAtt->isAvailable());

        // 3. Rejected file
        $rejectFile = UploadedFile::fake()->createWithContent(
            'test-trigger-reject.txt',
            'TEST_MALWARE_REJECT_TRIGGER_CONTENT'
        );
        $rejectedAtt = $uploader->handle($admin, new AttachmentUploadCommand(
            file: $rejectFile,
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));
        self::assertSame(AttachmentScanStatus::Rejected, $rejectedAtt->scan_status);
        self::assertFalse($rejectedAtt->isAvailable());
    }

    public function test_quarantined_and_rejected_files_cannot_be_downloaded(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $this->app->bind(AttachmentScannerInterface::class, LocalDeterministicAttachmentScanner::class);

        $uploader = app(UploadMedicalAttachment::class);
        $downloader = app(DownloadMedicalAttachment::class);

        // Upload quarantined file
        $quarantineFile = UploadedFile::fake()->createWithContent(
            'test-trigger-quarantine.txt',
            'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
        );
        $quarantinedAtt = $uploader->handle($admin, new AttachmentUploadCommand(
            file: $quarantineFile,
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));

        $this->expectException(AttachmentNotAvailableException::class);
        $downloader->handle($admin, $quarantinedAtt);
    }

    public function test_temporary_signed_access_lifecycle_and_security_controls(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $this->app->bind(AttachmentScannerInterface::class, LocalDeterministicAttachmentScanner::class);

        $uploader = app(UploadMedicalAttachment::class);
        $file = $this->fakePdf('medical_report.pdf');

        $attachment = $uploader->handle($admin, new AttachmentUploadCommand(
            file: $file,
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));

        $urlGenerator = app(GetTemporaryAttachmentUrl::class);

        // 1. Valid temporary signed URL succeeds for authorized actor
        $signedUrl = $urlGenerator->handle($admin, $attachment, 15);
        $response = $this->actingAs($admin)->get($signedUrl);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('medical_report.pdf', (string) $response->headers->get('Content-Disposition'));

        // 2. Expired signed URL fails closed (403)
        $expiredUrl = URL::temporarySignedRoute(
            'admin.attachments.download',
            now()->subMinutes(1),
            ['uuid' => $attachment->uuid],
        );
        $expiredResponse = $this->actingAs($admin)->get($expiredUrl);
        $expiredResponse->assertForbidden();

        // 3. Tampered signature fails closed (403)
        $tamperedUrl = $signedUrl.'&extra=tampered';
        $tamperedResponse = $this->actingAs($admin)->get($tamperedUrl);
        $tamperedResponse->assertForbidden();

        // 4. Inactive membership fails closed (403)
        $inactiveUser = User::factory()->create();
        OrganizationMembership::factory()->create([
            'organization_id' => $organization->getKey(),
            'user_id' => $inactiveUser->getKey(),
            'role' => OrganizationRole::Administrator,
            'is_active' => false,
        ]);
        $inactiveResponse = $this->actingAs($inactiveUser)->get($signedUrl);
        $inactiveResponse->assertForbidden();
    }

    public function test_cross_organization_staff_cannot_download_attachment_with_signed_url(): void
    {
        [$orgA, $adminA, $clientA] = $this->setupOrganizationWithClient();
        [$orgB, $adminB, $clientB] = $this->setupOrganizationWithClient();
        $this->app->bind(AttachmentScannerInterface::class, LocalDeterministicAttachmentScanner::class);

        app(OrganizationContext::class)->set($orgA);
        $file = $this->fakePdf('org_a_report.pdf');
        $attachmentA = app(UploadMedicalAttachment::class)->handle($adminA, new AttachmentUploadCommand(
            file: $file,
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $clientA->getKey(),
        ));

        $signedUrl = app(GetTemporaryAttachmentUrl::class)->handle($adminA, $attachmentA, 15);

        // Admin B from Org B attempts to use the URL
        config()->set('tenancy.default_organization_id', $orgB->getKey());
        app(OrganizationContext::class)->set($orgB);

        $response = $this->actingAs($adminB)->get($signedUrl);
        $response->assertNotFound();
    }

    public function test_persisted_scanner_metadata_only_contains_safe_allowlisted_keys(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $this->app->bind(AttachmentScannerInterface::class, LocalDeterministicAttachmentScanner::class);

        $file = $this->fakePdf('audit_check.pdf');
        $attachment = app(UploadMedicalAttachment::class)->handle($admin, new AttachmentUploadCommand(
            file: $file,
            attachmentType: AttachmentType::MedicalReport,
            clientId: (int) $client->getKey(),
        ));

        $metadata = $attachment->scan_result_metadata;
        self::assertIsArray($metadata);

        $allowedKeys = ['scanner_name', 'scanned_at', 'matched_rule', 'reason'];
        foreach (array_keys($metadata) as $key) {
            self::assertContains($key, $allowedKeys, "Unsafe metadata key {$key} persisted");
        }

        // Verify no raw host paths or secret data in metadata
        $encoded = json_encode($metadata);
        self::assertStringNotContainsString('/tmp', (string) $encoded);
        self::assertStringNotContainsString('password', (string) $encoded);
    }

    private function fakePdf(string $name = 'test.pdf'): UploadedFile
    {
        $pdfContent = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 595 842]>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n150\n%%EOF";

        return UploadedFile::fake()->createWithContent($name, $pdfContent);
    }

    private function fakeWebp(string $name = 'test.webp'): UploadedFile
    {
        $webpHeader = "RIFF\x24\x00\x00\x00WEBPVP8 \x18\x00\x00\x00\x30\x01\x00\x9d\x01\x2a\x01\x00\x01\x00\x02\x00\x34\x25\xa4\x00\x03\x70\x00\xfe\xfb\xfd\x00\x00";

        return UploadedFile::fake()->createWithContent($name, $webpHeader);
    }

    /** @return array{0: Organization, 1: User, 2: Client} */
    private function setupOrganizationWithClient(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->create(['organization_id' => $organization->getKey()]);

        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }
}
