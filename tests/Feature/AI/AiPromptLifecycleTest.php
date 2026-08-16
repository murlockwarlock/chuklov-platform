<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\ActivatePromptVersion;
use App\Modules\AI\Application\Actions\CreatePromptDraft;
use App\Modules\AI\Application\Actions\ExportPromptBundle;
use App\Modules\AI\Application\Actions\ImportPromptBundle;
use App\Modules\AI\Application\Actions\RollbackPromptVersion;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\PromptVersionStatus;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiPromptLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Chuklov Clinic',
            'slug' => 'chuklov-clinic',
        ]);

        $this->user = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();

        config()->set('tenancy.default_organization_id', $this->organization->id);
        app(OrganizationContext::class)->set($this->organization);
    }

    public function test_prompt_version_lifecycle_and_rollback(): void
    {
        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => 'companion_prompt',
            'name' => 'Клиентский компаньон',
            'capability' => AiCapability::ClientCompanion,
        ]);

        /** @var CreatePromptDraft $createDraft */
        $createDraft = app(CreatePromptDraft::class);

        /** @var ActivatePromptVersion $activate */
        $activate = app(ActivatePromptVersion::class);

        /** @var RollbackPromptVersion $rollback */
        $rollback = app(RollbackPromptVersion::class);

        // 1. Create version 1 draft
        $v1 = $createDraft->handle($this->user, $prompt->id, [
            'system_prompt' => 'Инструкция v1',
            'user_prompt_template' => '{{query}}',
        ]);
        $this->assertSame(1, $v1->version);
        $this->assertSame(PromptVersionStatus::Draft, $v1->status);

        // 2. Activate version 1
        $v1Active = $activate->handle($this->user, $v1->id);
        $this->assertSame(PromptVersionStatus::Active, $v1Active->status);
        $prompt->refresh();
        $this->assertSame($v1->id, $prompt->active_version_id);

        // 3. Create version 2 draft
        $v2 = $createDraft->handle($this->user, $prompt->id, [
            'system_prompt' => 'Инструкция v2',
            'user_prompt_template' => '{{query}}',
        ]);
        $this->assertSame(2, $v2->version);

        // 4. Activate version 2 -> v1 should become retired
        $v2Active = $activate->handle($this->user, $v2->id);
        $this->assertSame(PromptVersionStatus::Active, $v2Active->status);
        $prompt->refresh();
        $this->assertSame($v2->id, $prompt->active_version_id);

        $v1->refresh();
        $this->assertSame(PromptVersionStatus::Retired, $v1->status);

        // 5. Rollback to version 1
        $rolledBack = $rollback->handle($this->user, $prompt->id, 1);
        $this->assertSame(PromptVersionStatus::Active, $rolledBack->status);
        $prompt->refresh();
        $this->assertSame($v1->id, $prompt->active_version_id);
    }

    public function test_export_and_import_prompt_bundle(): void
    {
        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->id,
            'key' => 'qa_prompt',
            'name' => 'Вопрос-ответ',
            'capability' => AiCapability::KnowledgeRetrievalQa,
        ]);

        $version = AiPromptVersion::create([
            'organization_id' => $this->organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'QA Инструкция',
            'user_prompt_template' => 'Вопрос: {{query}}',
        ]);
        $prompt->update(['active_version_id' => $version->id]);

        /** @var ExportPromptBundle $export */
        $export = app(ExportPromptBundle::class);

        /** @var ImportPromptBundle $import */
        $import = app(ImportPromptBundle::class);

        $bundle = $export->handle($this->user, $version->id);
        $this->assertSame('qa_prompt', $bundle->promptKey);
        $this->assertSame('QA Инструкция', $bundle->systemPrompt);

        // Create new organization and import bundle
        $otherOrg = Organization::create([
            'name' => 'Second Clinic',
            'slug' => 'second-clinic',
        ]);
        $otherUser = User::factory()->forOrganization($otherOrg, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $otherOrg->id);
        app(OrganizationContext::class)->set($otherOrg);

        $importedVersion = $import->handle($otherUser, $bundle);
        $this->assertSame($otherOrg->id, $importedVersion->organization_id);

        $importedPrompt = AiPrompt::where('organization_id', $otherOrg->id)->where('key', 'qa_prompt')->first();
        $this->assertNotNull($importedPrompt);
        $this->assertSame('Вопрос-ответ', $importedPrompt->name);
    }
}
