<?php

namespace Tests\Integration;

use App\Modules\B2B\Domain\Enums\B2bFormConfigurationStatus;
use App\Modules\B2B\Domain\Models\B2bFormConfiguration;
use App\Modules\Identity\Domain\Enums\PatientAccessGrantScope;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\LegalDocument;
use App\Modules\Identity\Domain\Models\PatientAccessGrant;
use App\Modules\Identity\Domain\Models\PatientProfile;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OwnerConfigurationConcurrencyPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL partial-index verification requires PostgreSQL.');
        }
    }

    public function test_b2b_configuration_has_one_draft_and_one_published_version_per_organization(): void
    {
        $organization = Organization::factory()->create();
        $base = [
            'organization_id' => $organization->getKey(),
            'version' => 1,
            'fields' => [],
        ];

        B2bFormConfiguration::query()->create([
            ...$base,
            'status' => B2bFormConfigurationStatus::Draft,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        B2bFormConfiguration::query()->create([
            ...$base,
            'status' => B2bFormConfigurationStatus::Draft,
            'version' => 2,
        ]);
    }

    public function test_patient_access_has_one_active_grant_per_subject_and_delegate(): void
    {
        $organization = Organization::factory()->create();
        $owner = Client::factory()->forOrganization($organization)->create();
        $clinicalClient = Client::factory()->forOrganization($organization)->create();
        $delegate = Client::factory()->forOrganization($organization)->create(['birth_date' => '1988-03-04']);
        $patient = new PatientProfile;
        $patient->forceFill([
            'organization_id' => $organization->getKey(),
            'owner_client_id' => $owner->getKey(),
            'clinical_client_id' => $clinicalClient->getKey(),
            'first_name' => 'Synthetic',
            'last_name' => 'Patient',
            'relationship' => 'CHILD',
            'gender' => 'MALE',
            'birth_date' => '2015-03-04',
        ]);
        $patient->save();
        $policy = LegalDocument::factory()->forOrganization($organization)->published()->create([
            'document_type' => 'delegated_medical_access',
            'purpose' => 'delegated_medical_access_policy',
            'is_required' => false,
            'version' => 'synthetic-1',
        ]);
        $attributes = [
            'organization_id' => $organization->getKey(),
            'patient_profile_id' => $patient->getKey(),
            'grantor_client_id' => $owner->getKey(),
            'grantee_client_id' => $delegate->getKey(),
            'legal_document_id' => $policy->getKey(),
            'legal_document_version' => $policy->version,
            'scope' => PatientAccessGrantScope::MedicalRecords,
            'request_key' => '00000000-0000-4000-8000-000000000031',
            'granted_at' => now(),
        ];
        $grant = new PatientAccessGrant;
        $grant->forceFill($attributes)->save();

        $this->expectException(UniqueConstraintViolationException::class);
        $duplicate = new PatientAccessGrant;
        $duplicate->forceFill([
            ...$attributes,
            'request_key' => '00000000-0000-4000-8000-000000000032',
        ])->save();
    }
}
