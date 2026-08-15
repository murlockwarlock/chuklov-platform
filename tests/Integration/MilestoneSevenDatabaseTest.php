<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Attachments\Domain\Enums\AttachmentScanStatus;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Models\MedicalProfile;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationMembership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MilestoneSevenDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_composite_foreign_key_rejects_cross_organization_medical_profile(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $clientA = Client::factory()->forOrganization($organizationA)->create();

        $profile = new MedicalProfile;
        $profile->forceFill([
            'organization_id' => $organizationB->getKey(),
            'client_id' => $clientA->getKey(),
            'anamnesis' => 'encrypted_anamnesis',
            'encryption_key_version' => 1,
        ]);

        $this->expectException(QueryException::class);
        $profile->save();
    }

    public function test_postgresql_unique_constraint_enforces_one_medical_profile_per_client(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();

        $profile1 = new MedicalProfile;
        $profile1->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'anamnesis' => 'encrypted_anamnesis_1',
            'encryption_key_version' => 1,
        ]);
        $profile1->save();

        $profile2 = new MedicalProfile;
        $profile2->forceFill([
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'anamnesis' => 'encrypted_anamnesis_2',
            'encryption_key_version' => 1,
        ]);

        $this->expectException(QueryException::class);
        $profile2->save();
    }

    public function test_postgresql_composite_foreign_key_rejects_cross_organization_medical_attachment_client(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $clientA = Client::factory()->forOrganization($organizationA)->create();
        $userB = User::factory()->forOrganization($organizationB, OrganizationRole::Administrator)->create();

        $attachment = new MedicalAttachment;
        $attachment->forceFill([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organizationB->getKey(),
            'client_id' => $clientA->getKey(), // Client belongs to Org A, but attachment is for Org B
            'uploaded_by_user_id' => $userB->getKey(),
            'attachment_type' => AttachmentType::MedicalReport,
            'disk' => 'private',
            'storage_path' => 'medical/attachments/'.$organizationB->getKey().'/test.pdf',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256_checksum' => 'checksum',
            'scan_status' => AttachmentScanStatus::Cleared,
            'scanned_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $attachment->save();
    }

    public function test_postgresql_composite_foreign_key_rejects_cross_organization_medical_attachment_uploader(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $clientA = Client::factory()->forOrganization($organizationA)->create();
        $userB = User::factory()->forOrganization($organizationB, OrganizationRole::Administrator)->create();

        $attachment = new MedicalAttachment;
        $attachment->forceFill([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organizationA->getKey(),
            'client_id' => $clientA->getKey(),
            'uploaded_by_user_id' => $userB->getKey(), // User B is not a member of Org A
            'attachment_type' => AttachmentType::MedicalReport,
            'disk' => 'private',
            'storage_path' => 'medical/attachments/'.$organizationA->getKey().'/test.pdf',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256_checksum' => 'checksum',
            'scan_status' => AttachmentScanStatus::Cleared,
            'scanned_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $attachment->save();
    }

    public function test_postgresql_rejects_client_less_medical_attachment(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();

        $attachment = new MedicalAttachment;
        $attachment->forceFill([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->getKey(),
            'client_id' => null, // Required NOT NULL
            'uploaded_by_user_id' => $user->getKey(),
            'attachment_type' => AttachmentType::MedicalReport,
            'disk' => 'private',
            'storage_path' => 'medical/attachments/'.$organization->getKey().'/test.pdf',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256_checksum' => 'checksum',
            'scan_status' => AttachmentScanStatus::Cleared,
            'scanned_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $attachment->save();
    }

    public function test_postgresql_composite_foreign_key_restricts_deletion_of_uploader_membership(): void
    {
        $organization = Organization::factory()->create();
        $client = Client::factory()->forOrganization($organization)->create();
        $user = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();

        $attachment = new MedicalAttachment;
        $attachment->forceFill([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'uploaded_by_user_id' => $user->getKey(),
            'attachment_type' => AttachmentType::MedicalReport,
            'disk' => 'private',
            'storage_path' => 'medical/attachments/'.$organization->getKey().'/test.pdf',
            'original_filename' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256_checksum' => 'checksum',
            'scan_status' => AttachmentScanStatus::Cleared,
            'scanned_at' => now(),
        ]);
        $attachment->save();

        // Attempting to delete the organization membership referenced by the attachment must fail
        $membership = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        $this->expectException(QueryException::class);
        $membership->delete();
    }
}
