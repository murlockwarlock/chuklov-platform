<?php

namespace Tests\Feature\ClientCompanion;

use App\Filament\Resources\Clients\ClientResource;
use App\Models\User;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Actions\RecordCompanionFeedback;
use App\Modules\ClientCompanion\Application\Actions\ReplyToCompanion;
use App\Modules\ClientCompanion\Application\Services\CompanionExportService;
use App\Modules\ClientCompanion\Application\Services\ReadCompanionConversation;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionFeedbackValue;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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
        self::assertSame('AI временно приостановлен', $history['stateLabel']);
        self::assertNotNull($history['openEscalation']);
        self::assertTrue(collect($history['messages'])->contains(fn (array $item): bool => $item['type'] === 'handoff'));
        self::assertSame($conversation->getKey(), $turn->conversation_id);
        self::assertSame($aiMessage->getKey(), $turn->outbound_message_id);

        $this->actingAs($this->admin)
            ->get(ClientResource::getUrl('companion', ['record' => $this->client]))
            ->assertOk()
            ->assertSee('AI-компаньон / История общения')
            ->assertSee('Портал')
            ->assertSee('Telegram')
            ->assertSee('Ответ специалиста');
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

    public function test_history_exports_are_stable_and_pseudonymized_without_plaintext_in_audit_metadata(): void
    {
        $this->seedHandoffHistory();
        $export = app(CompanionExportService::class);

        $txt = $export->history($this->admin, $this->client, 'txt', 'identified');
        self::assertStringContainsString('Мария Компаньон', $txt);
        self::assertStringContainsString('[Telegram] Client:', $txt);
        self::assertStringContainsString('Specialist:', $txt);
        self::assertStringNotContainsString('<b>', $txt);

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

    public function test_foreign_client_history_export_is_rejected(): void
    {
        $otherOrganization = Organization::factory()->create();
        $foreignClient = Client::factory()->forOrganization($otherOrganization)->create();

        $this->expectException(AuthorizationException::class);
        app(CompanionExportService::class)->history($this->admin, $foreignClient, 'txt', 'pseudonymized');
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
