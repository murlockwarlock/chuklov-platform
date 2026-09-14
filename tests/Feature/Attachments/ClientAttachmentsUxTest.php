<?php

namespace Tests\Feature\Attachments;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ClientAttachmentsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ClientClinicalAiRelationManager;
use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

final class ClientAttachmentsUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_medical_report_has_preview_download_and_initial_analysis_state(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $attachment = $this->attachment($organization, $admin, $client);

        $component = $this->mount($admin, $client);

        $component
            ->assertSuccessful()
            ->assertTableColumnStateSet('analysis_status', 'Не запускался', $attachment)
            ->assertTableActionVisible('preview', $attachment)
            ->assertTableActionVisible('download', $attachment)
            ->assertTableActionVisible('startDocumentAnalysis', $attachment)
            ->assertTableActionHidden('retryDocumentAnalysis', $attachment)
            ->assertTableActionHidden('openDocumentAnalysisResult', $attachment);

        self::assertSame('Просмотр', $component->instance()->getTable()->getAction('preview')->getLabel());
        self::assertSame('Скачать', $component->instance()->getTable()->getAction('download')->getLabel());
        self::assertNull($component->instance()->getTable()->getPollingInterval());

        $component->mountTableAction('preview', $attachment);
        self::assertStringContainsString('<iframe', $component->getMountedActionModalHtml());
    }

    public function test_latest_document_run_controls_status_polling_and_actions(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $attachment = $this->attachment($organization, $admin, $client);
        $run = $this->createAiRun($organization, $client, $attachment, AiRunStatus::Queued);

        $active = $this->mount($admin, $client);
        $active
            ->assertTableColumnStateSet('analysis_status', 'В очереди', $attachment)
            ->assertTableActionHidden('startDocumentAnalysis', $attachment)
            ->assertTableActionHidden('retryDocumentAnalysis', $attachment)
            ->assertTableActionHidden('openDocumentAnalysisResult', $attachment);
        self::assertSame('5s', $active->instance()->getTable()->getPollingInterval());

        $run->update(['status' => AiRunStatus::Succeeded]);
        $active->call('$refresh');
        $active
            ->assertTableColumnStateSet('analysis_status', 'Готово', $attachment)
            ->assertTableActionVisible('openDocumentAnalysisResult', $attachment);
        self::assertNull($active->instance()->getTable()->getPollingInterval());

        $succeeded = $this->mount($admin, $client);
        $succeeded
            ->assertTableColumnStateSet('analysis_status', 'Готово', $attachment)
            ->assertTableActionVisible('openDocumentAnalysisResult', $attachment)
            ->assertTableActionVisible('retryDocumentAnalysis', $attachment)
            ->assertTableActionHidden('startDocumentAnalysis', $attachment);
        self::assertNull($succeeded->instance()->getTable()->getPollingInterval());

        $failed = $this->createAiRun($organization, $client, $attachment, AiRunStatus::Failed);
        $latest = $this->mount($admin, $client);
        $latest
            ->assertTableColumnStateSet('analysis_status', 'Ошибка', $attachment)
            ->assertTableActionVisible('retryDocumentAnalysis', $attachment)
            ->assertTableActionHidden('startDocumentAnalysis', $attachment)
            ->assertTableActionHidden('openDocumentAnalysisResult', $attachment);
        self::assertNull($latest->instance()->getTable()->getPollingInterval());
        self::assertSame(2, AiRun::query()->where('client_id', $client->getKey())->count());
        self::assertNotSame($run->getKey(), $failed->getKey());
    }

    public function test_preparing_queued_and_running_runs_keep_bounded_polling_and_human_statuses(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $preparingAttachment = $this->attachment($organization, $admin, $client, 'preparing.pdf');
        $queuedAttachment = $this->attachment($organization, $admin, $client, 'queued.pdf');
        $runningAttachment = $this->attachment($organization, $admin, $client, 'running.pdf');
        $this->createAiRun($organization, $client, $preparingAttachment, AiRunStatus::Preparing);
        $this->createAiRun($organization, $client, $queuedAttachment, AiRunStatus::Queued);
        $this->createAiRun($organization, $client, $runningAttachment, AiRunStatus::Running);

        $component = $this->mount($admin, $client);

        $component
            ->assertTableColumnStateSet('analysis_status', 'В очереди', $preparingAttachment)
            ->assertTableColumnStateSet('analysis_status', 'В очереди', $queuedAttachment)
            ->assertTableColumnStateSet('analysis_status', 'Анализируется', $runningAttachment)
            ->assertTableActionHidden('startDocumentAnalysis', $preparingAttachment)
            ->assertTableActionHidden('startDocumentAnalysis', $queuedAttachment)
            ->assertTableActionHidden('startDocumentAnalysis', $runningAttachment);
        self::assertSame('5s', $component->instance()->getTable()->getPollingInterval());
    }

    public function test_file_row_and_clinical_ai_tab_use_the_same_document_run_result_flow(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $attachment = $this->attachment($organization, $admin, $client);
        $run = $this->createAiRun($organization, $client, $attachment, AiRunStatus::Succeeded);

        $attachments = $this->mount($admin, $client);
        $attachments
            ->assertTableColumnStateSet('analysis_status', 'Готово', $attachment)
            ->assertTableActionVisible('openDocumentAnalysisResult', $attachment);
        $attachments->mountTableAction('openDocumentAnalysisResult', $attachment);
        self::assertStringContainsString('Результат анализа', $attachments->getMountedActionModalHtml());

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $clinicalAi = Livewire::actingAs($admin)->test(ClientClinicalAiRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => ViewClient::class,
        ]);
        $clinicalAi
            ->assertSuccessful()
            ->assertTableColumnStateSet('status', AiRunStatus::Succeeded, $run)
            ->assertTableActionVisible('openResult', $run);
        $clinicalAi->mountTableAction('openResult', $run);
        self::assertStringContainsString('Результат анализа', $clinicalAi->getMountedActionModalHtml());
    }

    public function test_foreign_client_runs_do_not_become_file_row_status(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $attachment = $this->attachment($organization, $admin, $client);
        $foreignOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($foreignOrganization)->create();
        $foreignAdmin = User::factory()->forOrganization($foreignOrganization, OrganizationRole::Administrator)->create();
        $foreignAttachment = $this->attachment($foreignOrganization, $foreignAdmin, $foreignClient);
        $this->createAiRun($foreignOrganization, $foreignClient, $foreignAttachment, AiRunStatus::Running);

        $component = $this->mount($admin, $client);

        $component
            ->assertTableColumnStateSet('analysis_status', 'Не запускался', $attachment)
            ->assertTableActionVisible('startDocumentAnalysis', $attachment);
        self::assertNull($component->instance()->getTable()->getPollingInterval());
    }

    public function test_analysis_controls_and_status_are_hidden_without_ai_run_permission(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $attachment = $this->attachment($organization, $admin, $client);
        $authorizer = Mockery::mock(OrganizationAuthorizer::class, [app(OrganizationContext::class)])->makePartial();
        $authorizer->shouldReceive('allows')->andReturnUsing(
            static fn (User $actor, Organization $currentOrganization, OrganizationPermission $permission): bool => $permission !== OrganizationPermission::ViewAiRuns,
        );
        $this->app->instance(OrganizationAuthorizer::class, $authorizer);

        $component = $this->mount($admin, $client);

        $component
            ->assertTableColumnHidden('analysis_status')
            ->assertTableActionVisible('preview', $attachment)
            ->assertTableActionVisible('download', $attachment)
            ->assertTableActionHidden('startDocumentAnalysis', $attachment)
            ->assertTableActionHidden('retryDocumentAnalysis', $attachment)
            ->assertTableActionHidden('openDocumentAnalysisResult', $attachment);
        self::assertNull($component->instance()->getTable()->getPollingInterval());
    }

    private function mount(User $admin, Client $client): mixed
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::actingAs($admin)->test(ClientAttachmentsRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => ViewClient::class,
        ]);
    }

    /** @return array{0: Organization, 1: User, 2: Client} */
    private function setupOrganizationWithClient(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $client = Client::factory()->forOrganization($organization)->create();

        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin, $client];
    }

    private function attachment(
        Organization $organization,
        User $admin,
        Client $client,
        string $filename = 'report.pdf',
    ): MedicalAttachment {
        $uuid = (string) Str::uuid();
        $path = "medical/attachments/{$organization->getKey()}/{$uuid}.pdf";
        Storage::disk('private')->put($path, '%PDF-1.4');

        $attachment = new MedicalAttachment;
        $attachment->forceFill([
            'uuid' => $uuid,
            'organization_id' => $organization->getKey(),
            'client_id' => $client->getKey(),
            'uploaded_by_user_id' => $admin->getKey(),
            'attachment_type' => AttachmentType::MedicalReport,
            'disk' => 'private',
            'storage_path' => $path,
            'original_filename' => $filename,
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'sha256_checksum' => hash('sha256', '%PDF-1.4'),
        ]);
        $attachment->save();

        return $attachment;
    }

    private function createAiRun(
        Organization $organization,
        Client $client,
        MedicalAttachment $attachment,
        AiRunStatus $status,
    ): AiRun {
        return AiRun::create([
            'organization_id' => $organization->getKey(),
            'capability' => AiCapability::ClinicalDocumentExtraction,
            'workflow_key' => AiCapability::ClinicalDocumentExtraction->value,
            'origin' => AiRunOrigin::User,
            'execution_mode' => AiExecutionMode::Async,
            'client_id' => $client->getKey(),
            'status' => $status,
            'input_references' => [
                ['type' => 'client', 'id' => $client->getKey()],
                ['type' => 'medical_attachment', 'id' => $attachment->getKey()],
            ],
            'context_provenance' => [],
            'token_usage' => [],
        ]);
    }
}
