<?php

namespace Tests\Feature\ClientCompanion;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ClientCompanionHistory;
use App\Models\User;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Actions\RecordCompanionFeedback;
use App\Modules\ClientCompanion\Application\Actions\ReplyToCompanion;
use App\Modules\ClientCompanion\Application\Actions\UploadCompanionCommunicationAttachment;
use App\Modules\ClientCompanion\Application\Services\CompanionExportService;
use App\Modules\ClientCompanion\Application\Services\ListCompanionCommunicationAttachments;
use App\Modules\ClientCompanion\Application\Services\ReadCompanionConversation;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionFeedbackValue;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionMessageAttachment;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Security\Domain\Models\AuditEvent;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class ClientCompanionCrmTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $admin;

    private User $staff;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        OrganizationFeatureFlag::factory()->forOrganization($this->organization)->create([
            'feature_key' => OrganizationFeature::ClientRecords->value,
            'enabled' => true,
        ]);
        $this->admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();
        $this->staff = User::factory()->forOrganization($this->organization, OrganizationRole::Staff)->create();
        $this->client = Client::factory()->forOrganization($this->organization)->create([
            'full_name' => 'Мария Компаньон',
            'phone' => '+70000000001',
            'email' => 'maria-companion@example.com',
        ]);
        config()->set('tenancy.default_organization_id', $this->organization->getKey());
        app(OrganizationContext::class)->set($this->organization);
        Queue::fake();
    }

    public function test_client_record_exposes_one_chronological_cross_channel_companion_history(): void
    {
        [$conversation, $turn, $aiMessage] = $this->seedHandoffHistory();

        $history = app(ReadCompanionConversation::class)->forStaff($this->admin, $this->client);
        $messages = array_values(array_filter($history['messages'], static fn (array $item): bool => $item['type'] === 'message'));

        self::assertSame(['client', 'client', 'ai', 'staff'], array_column($messages, 'role'));
        self::assertSame(['Портал', 'Telegram', 'Telegram', 'Telegram'], array_column($messages, 'transportLabel'));
        self::assertSame('helpful', $messages[2]['feedback']);
        self::assertSame('Специалист отвечает', $history['stateLabel']);
        self::assertNotNull($history['openEscalation']);
        self::assertTrue(collect($history['messages'])->contains(fn (array $item): bool => $item['type'] === 'handoff'));
        self::assertSame($conversation->getKey(), $turn->conversation_id);
        self::assertSame($aiMessage->getKey(), $turn->outbound_message_id);

        $this->actingAs($this->admin)
            ->get(ClientResource::getUrl('companion', ['record' => $this->client]))
            ->assertOk()
            ->assertSee('Общение с клиентом')
            ->assertSee('Портал')
            ->assertSee('Telegram')
            ->assertSee('Написать сообщение');
    }

    public function test_staff_history_is_bounded_and_id_access_is_tenant_and_permission_scoped(): void
    {
        $this->seedHandoffHistory();
        config()->set('ai.companion.history_page_size', 1);

        $history = app(ReadCompanionConversation::class)->forStaff($this->staff, $this->client);
        self::assertLessThanOrEqual(2, count($history['messages']));
        self::assertSame([], array_values(array_filter(
            $history['messages'],
            static fn (array $item): bool => $item['type'] === 'message' && $item['traceUrl'] !== null,
        )));

        $otherClient = Client::factory()->forOrganization($this->organization)->create();
        $otherHistory = app(ReadCompanionConversation::class)->forStaff($this->staff, $otherClient);
        self::assertSame([], $otherHistory['messages']);

        $otherOrganization = Organization::factory()->create();
        $foreignAdmin = User::factory()->forOrganization($otherOrganization)->create();
        $this->expectException(AuthorizationException::class);
        app(ReadCompanionConversation::class)->forStaff($foreignAdmin, $this->client);
    }

    public function test_uncertain_telegram_delivery_is_explained_in_human_terms_to_staff(): void
    {
        [$conversation, $turn, $aiMessage] = $this->seedHandoffHistory();
        CompanionDelivery::query()->create([
            'organization_id' => $this->organization->getKey(),
            'turn_id' => $turn->getKey(),
            'conversation_message_id' => $aiMessage->getKey(),
            'channel' => 'telegram',
            'recipient_external_id' => 'crm-telegram-history',
            'chunk_index' => 0,
            'chunk_count' => 1,
            'status' => CompanionDeliveryStatus::Uncertain,
            'attempt_count' => 1,
            'uncertain_at' => now(),
        ]);

        $history = app(ReadCompanionConversation::class)->forStaff($this->admin, $this->client);
        $message = collect($history['messages'])->first(
            static fn (array $item): bool => $item['type'] === 'message' && $item['id'] === $aiMessage->getKey(),
        );

        self::assertIsArray($message);
        self::assertSame('Доставка в Telegram не подтверждена', $message['deliveryNotice']['title']);
        self::assertStringContainsString('Сообщение могло быть доставлено', $message['deliveryNotice']['body']);
        self::assertStringNotContainsString('uncertain', json_encode($message, JSON_THROW_ON_ERROR));
        self::assertSame($conversation->getKey(), $turn->conversation_id);
    }

    public function test_history_exports_are_stable_and_pseudonymized_without_plaintext_in_audit_metadata(): void
    {
        $this->seedHandoffHistory();
        $export = app(CompanionExportService::class);

        $txt = $export->history($this->admin, $this->client, 'txt', 'identified');
        self::assertStringContainsString('Мария Компаньон', $txt);
        self::assertStringContainsString('[Telegram] Client:', $txt);
        self::assertStringContainsString('Specialist:', $txt);
        self::assertStringNotContainsString('<b>', $txt);

        $pseudonymizedTxt = $export->history($this->admin, $this->client, 'txt', 'pseudonymized');
        self::assertStringContainsString('Идентификатор экспорта: client_1', $pseudonymizedTxt);
        self::assertStringNotContainsString($this->client->full_name, $pseudonymizedTxt);

        $identified = json_decode($export->history($this->admin, $this->client, 'json', 'identified'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('client_companion_history_v1', $identified['schema_version']);
        self::assertSame('client_'.$this->client->getKey(), $identified['identity']['label']);
        self::assertCount(4, $identified['messages']);

        $pseudonymized = json_decode($export->history($this->admin, $this->client, 'json', 'pseudonymized'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('client_1', $pseudonymized['identity']['label']);
        self::assertArrayNotHasKey('client_id', $pseudonymized['identity']);
        self::assertArrayNotHasKey('name', $pseudonymized['identity']);
        self::assertArrayNotHasKey('phone', $pseudonymized['identity']);
        self::assertArrayNotHasKey('email', $pseudonymized['identity']);
        self::assertStringNotContainsString($this->client->full_name, json_encode($pseudonymized, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString((string) $this->client->phone, json_encode($pseudonymized, JSON_THROW_ON_ERROR));

        $metadata = json_decode($export->metadata($this->admin, $this->client), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('client_companion_metadata_v1', $metadata['schema_version']);
        self::assertCount(2, $metadata['turns']);
        self::assertStringNotContainsString('Мария Компаньон', json_encode(
            AuditEvent::query()->get()->toArray(),
            JSON_THROW_ON_ERROR,
        ));

        $this->expectException(AuthorizationException::class);
        $export->metadata($this->staff, $this->client);
    }

    public function test_companion_page_uses_shared_composer_and_modal_actions_for_exports_and_metadata(): void
    {
        $this->seedHandoffHistory();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $component = Livewire::actingAs($this->admin)
            ->test(ClientCompanionHistory::class, ['record' => $this->client->getKey()])
            ->assertSuccessful()
            ->assertActionExists('export')
            ->assertActionExists('technicalMetadata')
            ->assertSee('Скачать историю')
            ->assertSee('Расширенные технические метаданные')
            ->assertDontSee(route('admin.clients.companion.export', ['client' => $this->client]))
            ->assertDontSee(route('admin.clients.companion.metadata-export', ['client' => $this->client]));

        $editor = $component->instance()->getSchemaComponent('form.body');
        self::assertInstanceOf(RichEditor::class, $editor);
        self::assertContains('emoji', array_merge(...$editor->getToolbarButtons()));
        self::assertContains('bulletList', array_merge(...$editor->getToolbarButtons()));
        self::assertContains('orderedList', array_merge(...$editor->getToolbarButtons()));

        $exportAction = $component->instance()->getAction('export');
        self::assertNotNull($exportAction);
        $exportSchema = $exportAction->getSchema(Schema::make($component->instance()));
        self::assertNotNull($exportSchema);
        self::assertSame(['format', 'identity'], array_values(array_map(
            static fn (mixed $field): string => $field->getName(),
            $exportSchema->getFlatComponents(withHidden: true),
        )));

        $metadataAction = $component->instance()->getAction('technicalMetadata');
        self::assertNotNull($metadataAction);
        self::assertTrue($metadataAction->isModalSlideOver());
        self::assertNotNull($metadataAction->getModalContent());

        $expected = app(CompanionExportService::class)->history($this->admin, $this->client, 'json', 'pseudonymized');
        $component
            ->callAction('export', ['format' => 'json', 'identity' => 'pseudonymized'])
            ->assertFileDownloaded('client-companion.json', $expected, 'application/json; charset=UTF-8')
            ->assertDontSee(route('admin.clients.companion.export', ['client' => $this->client]));
    }

    public function test_foreign_client_history_export_is_rejected(): void
    {
        $otherOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($otherOrganization)->create();

        $this->expectException(AuthorizationException::class);
        app(CompanionExportService::class)->history($this->admin, $foreignClient, 'txt', 'pseudonymized');
    }

    public function test_protected_attachments_are_not_available_to_the_outbound_companion_picker(): void
    {
        $this->seedHandoffHistory();
        $protected = MedicalAttachment::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->getKey(),
            'client_id' => $this->client->getKey(),
            'uploaded_by_user_id' => $this->admin->getKey(),
            'attachment_type' => AttachmentType::MedicalReport,
            'disk' => 'private',
            'storage_path' => 'medical/attachments/protected.pdf',
            'original_filename' => 'protected.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'sha256_checksum' => hash('sha256', 'protected'),
        ]);
        $allowed = MedicalAttachment::create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->getKey(),
            'client_id' => $this->client->getKey(),
            'uploaded_by_user_id' => $this->admin->getKey(),
            'attachment_type' => AttachmentType::CompanionDocument,
            'disk' => 'private',
            'storage_path' => 'medical/attachments/outbound.pdf',
            'original_filename' => 'outbound.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'sha256_checksum' => hash('sha256', 'outbound'),
        ]);

        $options = app(ListCompanionCommunicationAttachments::class)->options($this->admin, $this->client);
        self::assertArrayNotHasKey($protected->getKey(), $options);
        self::assertArrayHasKey($allowed->getKey(), $options);

        $this->expectException(ValidationException::class);
        app(ReplyToCompanion::class)->handle($this->admin, $this->client, 'Попытка отправки', [$protected->getKey()]);
    }

    public function test_allowed_communication_attachment_is_stored_and_linked_to_the_staff_message(): void
    {
        $this->seedHandoffHistory();
        Storage::fake('private');

        $attachment = app(UploadCompanionCommunicationAttachment::class)->handle(
            $this->admin,
            $this->client,
            UploadedFile::fake()->createWithContent('instructions.txt', 'Безопасная инструкция'),
        );
        app(ReplyToCompanion::class)->handle(
            $this->admin,
            $this->client,
            '<p>Инструкция во вложении</p>',
            [$attachment->getKey()],
        );

        $link = CompanionMessageAttachment::query()
            ->where('medical_attachment_id', $attachment->getKey())
            ->sole();
        self::assertSame($this->client->getKey(), $link->client_id);
        self::assertSame(AttachmentType::CompanionDocument, $attachment->fresh()->attachment_type);
        Storage::disk('private')->assertExists($attachment->storage_path);
    }

    public function test_closing_handoff_and_resuming_ai_is_one_authoritative_action(): void
    {
        [$conversation] = $this->seedHandoffHistory();
        $escalation = CompanionEscalation::query()->where('conversation_id', $conversation->getKey())->where('status', CompanionEscalationStatus::Open)->sole();

        $this->actingAs($this->admin)
            ->post(route('admin.clients.companion.resolve-and-resume', ['client' => $this->client]))
            ->assertRedirect()
            ->assertSessionHas('companion_status', 'Обращение закрыто, AI снова отвечает.');

        self::assertSame(ConversationAutomationState::AiActive, $conversation->fresh()->automation_state);
        self::assertSame(CompanionEscalationStatus::Resolved, $escalation->fresh()->status);
        self::assertSame($this->admin->getKey(), $escalation->fresh()->resolved_by_user_id);
    }

    /** @return array{0: Conversation, 1: CompanionTurn, 2: ConversationMessage} */
    private function seedHandoffHistory(): array
    {
        $accept = app(AcceptCompanionMessage::class);
        $baseTime = now();
        $portalTurn = $accept->handle(
            client: $this->client,
            channel: 'portal',
            body: 'Портал: как проходит восстановление?',
            idempotencyKey: 'crm-portal-history-0001',
            originExternalId: 'portal:crm-history-1',
            locale: 'ru',
        );
        Carbon::setTestNow($baseTime->copy()->addSeconds(2));
        $turn = $accept->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'Telegram: после сеанса тянет шея',
            idempotencyKey: null,
            originExternalId: 'crm-telegram-history:2',
            transportChatId: 'crm-telegram-history',
            locale: 'ru',
        );
        Carbon::setTestNow($baseTime->copy()->addSeconds(3));
        $conversation = Conversation::query()->whereKey($turn->conversation_id)->firstOrFail();
        $aiMessage = app(RecordCompanionMessage::class)->handle(
            organizationId: $this->organization->getKey(),
            client: $this->client,
            conversation: $conversation,
            channel: 'telegram',
            direction: ConversationDirection::Outbound,
            authorType: ConversationAuthorType::Ai,
            body: 'Сейчас нужен ответ специалиста.',
            contextEpoch: $turn->context_epoch,
            metadata: ['message_type' => 'handoff', 'locale' => 'ru', 'transport' => 'telegram'],
        );
        $turn->update([
            'status' => 'escalated',
            'outbound_message_id' => $aiMessage->getKey(),
            'escalated_at' => now(),
            'burst_expires_at' => now()->subSecond(),
        ]);
        $conversation->update(['automation_state' => ConversationAutomationState::HumanHandoff]);
        CompanionEscalation::create([
            'organization_id' => $this->organization->getKey(),
            'client_id' => $this->client->getKey(),
            'conversation_id' => $conversation->getKey(),
            'turn_id' => $turn->getKey(),
            'reason' => CompanionEscalationReason::OutOfScope,
            'status' => CompanionEscalationStatus::Open,
            'safe_metadata' => ['source' => 'test'],
            'opened_at' => now(),
        ]);
        app(RecordCompanionFeedback::class)->handle($this->client, $aiMessage->getKey(), CompanionFeedbackValue::Helpful);
        app(ReplyToCompanion::class)->handle($this->admin, $this->client, 'Ответ специалиста из CRM.');
        Carbon::setTestNow();

        return [$conversation->refresh(), $turn->fresh(), $aiMessage->refresh()];
    }
}
