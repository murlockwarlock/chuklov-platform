<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunAttempt;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Application\ReplaceOrganizationCredential;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiCredentialRotationProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Chuklov Clinic',
            'slug' => 'chuklov-clinic',
        ]);

        $this->admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();

        config()->set('tenancy.default_organization_id', $this->organization->id);
        app(OrganizationContext::class)->set($this->organization);
    }

    public function test_credential_rotation_updates_revision_while_historical_attempts_preserve_original_revision_snapshot(): void
    {
        $revisionA = (string) Str::uuid();

        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI Main',
            'revision_id' => $revisionA,
        ]);
        $credential->organization_id = max(0, (int) $this->organization->id);
        $credential->credentials = ['api_key' => 'sk-initial-secret'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'cred_prov_test',
            'status' => AiRunStatus::Succeeded,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $attempt = AiRunAttempt::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'attempt_number' => 1,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'credential_id' => $credential->id,
            'credential_revision' => $revisionA,
            'status' => 'succeeded',
            'budget_usage_date' => Carbon::now()->toDateString(),
            'pricing_snapshot' => [],
            'token_usage' => [],
        ]);

        // Rotate credentials using ReplaceOrganizationCredential
        $replaceAction = app(ReplaceOrganizationCredential::class);
        $updatedCredential = $replaceAction->handle(
            actor: $this->admin,
            provider: 'openai',
            credentialName: 'OpenAI Main',
            credentials: ['api_key' => 'sk-rotated-new-secret'],
        );

        $revisionB = $updatedCredential->revision_id;
        $this->assertNotSame($revisionA, $revisionB);

        // Historical attempt still holds snapshot of revision A
        $attempt->refresh();
        $this->assertSame($revisionA, $attempt->credential_revision);

        // Audit event verification
        $audit = AuditEvent::where('action', 'organization.credential.replaced')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($revisionA, $audit->metadata['old_revision_id']);
        $this->assertSame($revisionB, $audit->metadata['new_revision_id']);

        // Verify zero plaintext secrets or hashes leaked into audit metadata
        $metadataString = json_encode($audit->metadata);
        $this->assertStringNotContainsString('sk-initial-secret', (string) $metadataString);
        $this->assertStringNotContainsString('sk-rotated-new-secret', (string) $metadataString);
    }
}
