<?php

namespace Tests\Feature\Attachments;

use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ClientAttachmentsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ClientClinicalAiRelationManager;
use App\Filament\Support\ClinicalAiPresentation;
use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\HumanReviewStatus;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunPayload;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use Filament\Actions\ActionGroup;
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

    public function test_attachment_row_actions_are_compactly_grouped_to_avoid_horizontal_overflow(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $this->attachment($organization, $admin, $client);

        $actions = $this->mount($admin, $client)->instance()->getTable()->getRecordActions();

        self::assertCount(1, $actions);
        self::assertInstanceOf(ActionGroup::class, $actions[0]);
        self::assertSame('Действия', $actions[0]->getLabel());
        self::assertArrayHasKey('preview', $actions[0]->getFlatActions());
        self::assertArrayHasKey('download', $actions[0]->getFlatActions());
        self::assertArrayHasKey('startDocumentAnalysis', $actions[0]->getFlatActions());
        self::assertArrayHasKey('openDocumentAnalysisResult', $actions[0]->getFlatActions());
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
        $run = $this->createAiRun(
            organization: $organization,
            client: $client,
            attachment: $attachment,
            status: AiRunStatus::Succeeded,
            payload: [
                'exam_type' => 'МРТ',
                'anatomical_region' => 'Поясничный отдел',
                'key_findings' => [],
                'structural_deformations' => [],
                'critical_flags' => [],
                'plain_summary' => 'Человеческое резюме результата.',
            ],
            reviewStatus: HumanReviewStatus::PendingReview,
        );

        $attachments = $this->mount($admin, $client);
        $attachments
            ->assertTableColumnStateSet('analysis_status', 'Готово', $attachment)
            ->assertTableActionVisible('openDocumentAnalysisResult', $attachment);
        $attachments->mountTableAction('openDocumentAnalysisResult', $attachment);
        $attachmentsHtml = $attachments->getMountedActionModalHtml();
        $attachmentsData = $attachments->instance()->getMountedAction()->getRawData();
        self::assertStringContainsString('Результат анализа', $attachmentsHtml);
        self::assertStringContainsString('Человеческое резюме результата.', $attachmentsData['result']);
        self::assertNull($attachments->instance()->getMountedAction()->getModalSubmitAction());
        self::assertSame('Закрыть', $attachments->instance()->getMountedAction()->getModalCancelActionLabel());
        self::assertStringContainsString('Результат сохранён в истории Клинического AI.', $attachmentsData['lifecycle']);
        self::assertStringContainsString('Клиенту ничего не отправлено.', $attachmentsData['lifecycle']);
        self::assertStringContainsString('В медицинский профиль данные автоматически не внесены.', $attachmentsData['lifecycle']);

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
        $clinicalAiHtml = $clinicalAi->getMountedActionModalHtml();
        $clinicalAiData = $clinicalAi->instance()->getMountedAction()->getRawData();
        self::assertStringContainsString('Результат анализа', $clinicalAiHtml);
        self::assertStringContainsString('Человеческое резюме результата.', $clinicalAiData['result']);
        self::assertNull($clinicalAi->instance()->getMountedAction()->getModalSubmitAction());
        self::assertSame('Закрыть', $clinicalAi->instance()->getMountedAction()->getModalCancelActionLabel());
        self::assertStringContainsString('Проверьте результат', $clinicalAiData['lifecycle']);
    }

    public function test_result_lifecycle_uses_human_review_status_without_making_profile_or_delivery_claims(): void
    {
        $pending = ClinicalAiPresentation::reviewGuidance(HumanReviewStatus::PendingReview);
        $accepted = ClinicalAiPresentation::reviewGuidance(HumanReviewStatus::Accepted);
        $edited = ClinicalAiPresentation::reviewGuidance(HumanReviewStatus::EditedAndAccepted);
        $rejected = ClinicalAiPresentation::reviewGuidance(HumanReviewStatus::Rejected);

        self::assertStringContainsString('ещё не подтверждён специалистом', $pending);
        self::assertStringContainsString('Клиенту ничего не отправлено.', $pending);
        self::assertStringContainsString('может использоваться при формировании клинического резюме', $accepted);
        self::assertStringContainsString('может использоваться при формировании клинического резюме', $edited);
        self::assertStringContainsString('не используется как подтверждённый источник', $rejected);
        self::assertStringNotContainsString('internal', strtolower($pending.$accepted.$edited.$rejected));
    }

    public function test_files_and_mri_exposes_authoritative_review_actions_and_refreshes_state(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $acceptedAttachment = $this->attachment($organization, $admin, $client, 'accepted.pdf');
        $rejectedAttachment = $this->attachment($organization, $admin, $client, 'rejected.pdf');
        $acceptedRun = $this->createAiRun(
            organization: $organization,
            client: $client,
            attachment: $acceptedAttachment,
            status: AiRunStatus::Succeeded,
            payload: ['plain_summary' => 'Результат для проверки.'],
            reviewStatus: HumanReviewStatus::PendingReview,
        );
        $rejectedRun = $this->createAiRun(
            organization: $organization,
            client: $client,
            attachment: $rejectedAttachment,
            status: AiRunStatus::Succeeded,
            payload: ['plain_summary' => 'Результат для отклонения.'],
            reviewStatus: HumanReviewStatus::PendingReview,
        );

        $component = $this->mount($admin, $client);

        $component
            ->assertTableActionVisible('acceptDocumentAnalysisReview', $acceptedAttachment)
            ->assertTableActionVisible('rejectDocumentAnalysisReview', $acceptedAttachment)
            ->assertTableActionHasLabel('acceptDocumentAnalysisReview', 'Проверено')
            ->assertTableActionHasLabel('rejectDocumentAnalysisReview', 'Отклонить')
            ->mountTableAction('acceptDocumentAnalysisReview', $acceptedAttachment)
            ->callMountedTableAction()
            ->assertNotified('Результат подтверждён специалистом.');

        self::assertSame(HumanReviewStatus::Accepted, $acceptedRun->fresh()->human_review_status);
        $component
            ->assertTableActionHidden('acceptDocumentAnalysisReview', $acceptedAttachment)
            ->assertTableActionHidden('rejectDocumentAnalysisReview', $acceptedAttachment);

        $component
            ->mountTableAction('rejectDocumentAnalysisReview', $rejectedAttachment)
            ->setTableActionData([
                'reason_code' => 'incorrect_content',
                'notes' => 'Результат требует повторной проверки.',
            ])
            ->callMountedTableAction()
            ->assertNotified('Результат отклонён специалистом.');

        self::assertSame(HumanReviewStatus::Rejected, $rejectedRun->fresh()->human_review_status);
        $component
            ->assertTableActionHidden('acceptDocumentAnalysisReview', $rejectedAttachment)
            ->assertTableActionHidden('rejectDocumentAnalysisReview', $rejectedAttachment);
    }

    public function test_review_actions_require_review_permission_in_files_and_clinical_ai(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $attachment = $this->attachment($organization, $admin, $client);
        $run = $this->createAiRun(
            organization: $organization,
            client: $client,
            attachment: $attachment,
            status: AiRunStatus::Succeeded,
            payload: ['plain_summary' => 'Результат доступен для просмотра.'],
            reviewStatus: HumanReviewStatus::PendingReview,
        );
        $authorizer = Mockery::mock(OrganizationAuthorizer::class, [app(OrganizationContext::class)])->makePartial();
        $authorizer->shouldReceive('allows')->andReturnUsing(
            static fn (User $actor, Organization $currentOrganization, OrganizationPermission $permission): bool => $permission !== OrganizationPermission::ReviewAiProposals,
        );
        $this->app->instance(OrganizationAuthorizer::class, $authorizer);

        $attachments = $this->mount($admin, $client);
        $attachments
            ->assertTableActionVisible('openDocumentAnalysisResult', $attachment)
            ->assertTableActionHidden('acceptDocumentAnalysisReview', $attachment)
            ->assertTableActionHidden('rejectDocumentAnalysisReview', $attachment);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $clinicalAi = Livewire::actingAs($admin)->test(ClientClinicalAiRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => ViewClient::class,
        ]);
        $clinicalAi
            ->assertTableActionVisible('openResult', $run)
            ->assertTableActionHidden('acceptReview', $run)
            ->assertTableActionHidden('rejectReview', $run);
    }

    public function test_clinical_ai_review_uses_human_confirmation_and_removes_duplicate_actions_after_acceptance(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $attachment = $this->attachment($organization, $admin, $client);
        $run = $this->createAiRun(
            organization: $organization,
            client: $client,
            attachment: $attachment,
            status: AiRunStatus::Succeeded,
            payload: ['plain_summary' => 'Результат клинического анализа.'],
            reviewStatus: HumanReviewStatus::PendingReview,
        );

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $clinicalAi = Livewire::actingAs($admin)->test(ClientClinicalAiRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => ViewClient::class,
        ]);
        $clinicalAi
            ->assertTableActionVisible('acceptReview', $run)
            ->assertTableActionVisible('rejectReview', $run)
            ->mountTableAction('acceptReview', $run)
            ->callMountedTableAction()
            ->assertNotified('Результат подтверждён специалистом.');

        self::assertSame(HumanReviewStatus::Accepted, $run->fresh()->human_review_status);
        $clinicalAi
            ->assertTableActionHidden('acceptReview', $run)
            ->assertTableActionHidden('rejectReview', $run);
    }

    public function test_staff_without_trace_permission_sees_human_result_without_audit_or_internal_payload(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $attachment = $this->attachment($organization, $admin, $client);
        $run = $this->createAiRun(
            organization: $organization,
            client: $client,
            attachment: $attachment,
            status: AiRunStatus::Succeeded,
            payload: [
                'exam_type' => 'МРТ',
                'anatomical_region' => 'Поясничный отдел',
                'key_findings' => [],
                'structural_deformations' => [],
                'critical_flags' => [],
                'plain_summary' => 'Понятный результат для специалиста.',
                'internal_key' => 'не показывать',
            ],
            reviewStatus: HumanReviewStatus::PendingReview,
        );
        $run->update([
            'context_provenance' => [
                'attachments' => [[
                    'attachment_id' => 987654,
                    'attachment_uuid' => 'private-uuid',
                    'attachment_type' => AttachmentType::MedicalReport->value,
                    'mime_type' => 'application/pdf',
                ]],
            ],
        ]);
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();

        $attachments = $this->mount($staff, $client);
        $attachments->mountTableAction('openDocumentAnalysisResult', $attachment);
        $attachmentsHtml = $attachments->getMountedActionModalHtml();
        $attachmentsData = $attachments->instance()->getMountedAction()->getRawData();

        self::assertStringContainsString('Понятный результат для специалиста.', $attachmentsData['result']);
        self::assertSame('Ожидает проверки специалиста', $attachmentsData['review']);
        self::assertStringContainsString('Медицинский документ · PDF', $attachmentsData['sources']);
        self::assertStringNotContainsString('Техническая информация (аудит)', $attachmentsHtml);
        self::assertArrayNotHasKey('technical', $attachmentsData);
        self::assertStringNotContainsString('prompt_version_id', $attachmentsHtml);
        self::assertStringNotContainsString('model_release_id', $attachmentsHtml);
        self::assertStringNotContainsString('"exam_type"', $attachmentsData['result']);
        self::assertStringNotContainsString('internal_key', $attachmentsData['result']);
        self::assertStringNotContainsString('987654', $attachmentsData['sources']);
        self::assertStringNotContainsString('private-uuid', $attachmentsData['sources']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $clinicalAi = Livewire::actingAs($staff)->test(ClientClinicalAiRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => ViewClient::class,
        ]);
        $clinicalAi->mountTableAction('openResult', $run);
        $clinicalAiHtml = $clinicalAi->getMountedActionModalHtml();
        $clinicalAiData = $clinicalAi->instance()->getMountedAction()->getRawData();

        self::assertStringContainsString('Понятный результат для специалиста.', $clinicalAiData['result']);
        self::assertStringNotContainsString('Техническая информация (аудит)', $clinicalAiHtml);
        self::assertArrayNotHasKey('technical', $clinicalAiData);
        self::assertStringNotContainsString('"exam_type"', $clinicalAiData['result']);
    }

    public function test_trace_actor_sees_human_result_and_permissioned_audit_details(): void
    {
        [$organization, $admin, $client] = $this->setupOrganizationWithClient();
        $attachment = $this->attachment($organization, $admin, $client);
        $run = $this->createAiRun(
            organization: $organization,
            client: $client,
            attachment: $attachment,
            status: AiRunStatus::Succeeded,
            payload: [
                'exam_type' => 'МРТ',
                'anatomical_region' => 'Поясничный отдел',
                'key_findings' => [],
                'structural_deformations' => [],
                'critical_flags' => [],
                'plain_summary' => 'Human result remains primary.',
            ],
            reviewStatus: HumanReviewStatus::Accepted,
        );

        $component = $this->mount($admin, $client);
        $component->mountTableAction('openDocumentAnalysisResult', $attachment);
        $html = $component->getMountedActionModalHtml();
        $data = $component->instance()->getMountedAction()->getRawData();

        self::assertStringContainsString('Human result remains primary.', $data['result']);
        self::assertStringContainsString('Техническая информация (аудит)', $html);
        self::assertStringContainsString('Запуск: #'.$run->getKey(), $data['technical']);
        self::assertStringContainsString('Проверка: Принято специалистом', $data['technical']);
        self::assertStringNotContainsString('"exam_type"', $data['result']);
    }

    public function test_unknown_structured_payload_uses_safe_text_or_human_fallback(): void
    {
        self::assertStringContainsString(
            'Что обнаружено',
            ClinicalAiPresentation::result(
                AiCapability::ClinicalDocumentExtraction,
                ['key_findings' => [['location' => 'L4-L5', 'pathology' => 'Изменение']]],
                '{"key_findings":[{"location":"L4-L5","pathology":"Изменение"}]}',
            ),
        );
        self::assertStringContainsString(
            'Визуальные наблюдения',
            ClinicalAiPresentation::result(
                AiCapability::PostureAnalysis,
                ['visual_findings' => [['plane' => 'front', 'observations' => ['Плечи на разной высоте']]]],
                '{"visual_findings":[{"plane":"front","observations":["Плечи на разной высоте"]}]}',
            ),
        );
        self::assertStringContainsString(
            'Сводка по клиенту',
            ClinicalAiPresentation::result(
                AiCapability::ClinicalSynthesizer,
                ['client_summary' => 'Сводка', 'source_facts' => ['Факт']],
                '{"client_summary":"Сводка","source_facts":["Факт"]}',
            ),
        );
        self::assertSame(
            'Безопасный текст результата.',
            ClinicalAiPresentation::result(
                AiCapability::ClinicalDocumentExtraction,
                ['unknown_internal_key' => 'secret'],
                'Безопасный текст результата.',
            ),
        );
        self::assertSame(
            'Результат получен, но не может быть отображён в текущем формате.',
            ClinicalAiPresentation::result(
                AiCapability::ClinicalDocumentExtraction,
                ['unknown_internal_key' => 'secret'],
                '{"unknown_internal_key":"secret"}',
            ),
        );
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
        ?array $payload = null,
        HumanReviewStatus $reviewStatus = HumanReviewStatus::NotRequired,
    ): AiRun {
        $run = AiRun::create([
            'organization_id' => $organization->getKey(),
            'capability' => AiCapability::ClinicalDocumentExtraction,
            'workflow_key' => AiCapability::ClinicalDocumentExtraction->value,
            'origin' => AiRunOrigin::User,
            'execution_mode' => AiExecutionMode::Async,
            'client_id' => $client->getKey(),
            'status' => $status,
            'human_review_status' => $reviewStatus,
            'input_references' => [
                ['type' => 'client', 'id' => $client->getKey()],
                ['type' => 'medical_attachment', 'id' => $attachment->getKey()],
            ],
            'context_provenance' => [],
            'token_usage' => [],
        ]);

        if ($payload !== null) {
            $encryptor = app(MedicalEncryptorInterface::class);
            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);
            AiRunPayload::create([
                'organization_id' => $organization->getKey(),
                'ai_run_id' => $run->getKey(),
                'encryption_key_version' => 1,
                'encrypted_output_text' => $encryptor->encryptField(
                    $organization->getKey(),
                    $encodedPayload,
                    1,
                ),
                'encrypted_output_payload' => $encryptor->encryptField(
                    $organization->getKey(),
                    $encodedPayload,
                    1,
                ),
            ]);
        }

        return $run;
    }
}
