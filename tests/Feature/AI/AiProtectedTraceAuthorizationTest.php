<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\GetAiRunProtectedTrace;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class AiProtectedTraceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Chuklov Clinic',
            'slug' => 'chuklov-clinic',
        ]);

        $this->admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();
        $this->staff = User::factory()->forOrganization($this->organization, OrganizationRole::Staff)->create();

        config()->set('tenancy.default_organization_id', $this->organization->id);
        app(OrganizationContext::class)->set($this->organization);
    }

    public function test_administrator_with_view_ai_trace_permission_can_retrieve_decrypted_trace(): void
    {
        $encryptor = app(MedicalEncryptorInterface::class);

        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'trace_auth_test',
            'status' => AiRunStatus::Succeeded,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        AiRunPayload::create([
            'organization_id' => $this->organization->id,
            'ai_run_id' => $run->id,
            'encryption_key_version' => 1,
            'encrypted_system_prompt' => $encryptor->encryptField($this->organization->id, 'Секретная инструкция', 1),
            'encrypted_user_prompt' => $encryptor->encryptField($this->organization->id, 'Вопрос клиента с анамнезом', 1),
            'encrypted_output_text' => $encryptor->encryptField($this->organization->id, 'Клинический ответ ИИ', 1),
        ]);

        $traceAction = app(GetAiRunProtectedTrace::class);
        $trace = $traceAction->handle($this->admin, $run->id);

        $this->assertSame('Секретная инструкция', $trace->systemPrompt);
        $this->assertSame('Вопрос клиента с анамнезом', $trace->userPrompt);
        $this->assertSame('Клинический ответ ИИ', $trace->outputText);
    }

    public function test_staff_without_view_ai_trace_permission_is_rejected_with_403(): void
    {
        $run = AiRun::create([
            'organization_id' => $this->organization->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'staff_rejection_test',
            'status' => AiRunStatus::Succeeded,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $traceAction = app(GetAiRunProtectedTrace::class);

        $this->expectException(AuthorizationException::class);
        $traceAction->handle($this->staff, $run->id);
    }

    public function test_cross_organization_trace_retrieval_is_rejected_with_not_found(): void
    {
        $otherOrg = Organization::create([
            'name' => 'Other Clinic',
            'slug' => 'other-clinic',
        ]);

        $otherRun = AiRun::create([
            'organization_id' => $otherOrg->id,
            'capability' => AiCapability::ClientCompanion,
            'workflow_key' => 'other_org_run',
            'status' => AiRunStatus::Succeeded,
            'input_references' => [],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        $traceAction = app(GetAiRunProtectedTrace::class);

        // Admin of Org A tries to access Org B run trace
        $this->expectException(NotFoundHttpException::class);
        $traceAction->handle($this->admin, $otherRun->id);
    }
}
