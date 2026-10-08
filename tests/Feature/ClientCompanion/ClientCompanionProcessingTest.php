<?php

namespace Tests\Feature\ClientCompanion;

use App\Models\User;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Application\Data\AiRunResult;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiErrorCategory;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Exceptions\AiProviderUnavailableException;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Actions\ReplyToCompanion;
use App\Modules\ClientCompanion\Application\Actions\RequestCompanionHandoff;
use App\Modules\ClientCompanion\Application\Actions\ResolveCompanionHandoff;
use App\Modules\ClientCompanion\Application\Actions\RestoreLegacyCompanionAi;
use App\Modules\ClientCompanion\Application\Actions\RetryCompanionTurn;
use App\Modules\ClientCompanion\Application\Actions\TakeOverCompanionConversation;
use App\Modules\ClientCompanion\Application\Services\CompanionMessageBodyReader;
use App\Modules\ClientCompanion\Application\Services\CompanionTurnProcessor;
use App\Modules\ClientCompanion\Application\Services\ReadCompanionConversation;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationReason;
use App\Modules\ClientCompanion\Domain\Enums\CompanionEscalationStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnAttemptStatus;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Enums\RequestCompanionHandoffResult;
use App\Modules\ClientCompanion\Domain\Enums\RetryCompanionTurnResult;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionMessageAttachment;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnAttempt;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnMessage;
use App\Modules\ClientCompanion\Infrastructure\Jobs\DeliverCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

final class ClientCompanionProcessingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->client = Client::factory()->forOrganization($this->organization)->create();
        app(OrganizationContext::class)->set($this->organization);
        Queue::fake();
    }

    public function test_companion_processing_uses_the_existing_control_plane_and_creates_one_encrypted_response(): void
    {
        $engine = new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'reply',
                'reply' => 'Безопасный ответ из тестового провайдера.',
                'handoff_reason' => '',
                'suggested_safe_actions' => ['feedback_helpful'],
            ],
        ));
        $channel = new RecordingCompanionChannel;
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, $channel);

        $turn = $this->accept('Вопрос клиента');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $turn->refresh();
        self::assertSame(CompanionTurnStatus::Completed, $turn->status);
        self::assertNotNull($turn->outbound_message_id);
        self::assertSame(AiCapability::ClientCompanion, $engine->request?->capability);
        self::assertSame(AiRunOrigin::ClientCompanion, $engine->request?->origin);
        self::assertSame('Вопрос клиента', $engine->request?->inputVariables['current_message']);
        self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->whereNotNull('body')->count());
        self::assertSame(1, CompanionDelivery::query()->where('turn_id', $turn->getKey())->count());
        self::assertSame(['telegram:chat-1'], $channel->typingRecipients);
    }

    public function test_invalid_structured_result_fails_safely_without_exposing_provider_text(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: ['unexpected' => 'raw provider payload'],
        )));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('Нужен ответ');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $turn->refresh();
        self::assertSame(CompanionTurnStatus::Failed, $turn->status);
        $outbound = ConversationMessage::query()->findOrFail($turn->outbound_message_id);
        self::assertStringNotContainsString('raw provider payload', app(CompanionMessageBodyReader::class)->read($this->organization->getKey(), $outbound));
        self::assertSame('invalid_output', $turn->failure_code);
    }

    public function test_missing_ai_configuration_is_not_misclassified_as_a_provider_failure_or_handoff(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new NotConfiguredCompanionEngine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $first = $this->accept('Первый вопрос');
        $first->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());

        $second = $this->accept('Второй вопрос');
        $second->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $second->getKey());

        self::assertSame('not_configured', $first->fresh()->failure_code);
        self::assertSame('not_configured', $second->fresh()->failure_code);
        self::assertSame(ConversationAutomationState::AiActive, $second->conversation()->firstOrFail()->automation_state);
        self::assertSame(0, CompanionEscalation::query()->count());
        $failure = ConversationMessage::query()->findOrFail($second->fresh()->outbound_message_id);
        self::assertStringNotContainsString('provider', app(CompanionMessageBodyReader::class)->read($this->organization->getKey(), $failure));
        self::assertNotContains('retry_failed_turn', explode(',', (string) ($failure->metadata['safe_actions'] ?? '')));
        self::assertSame(2, ScenarioEvent::query()->where('event_name', ScenarioEventType::CompanionFallbackFailed)->count());
        self::assertSame('not_configured', ScenarioEvent::query()->latest('id')->firstOrFail()->payload['failure_code']);
    }

    public function test_untyped_preparation_configuration_failure_is_not_reclassified_as_repeated_provider_failure_or_handoff(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new InvalidArgumentCompanionEngine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $first = $this->accept('Первый вопрос без настройки');
        $first->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());

        $second = $this->accept('Второй вопрос без настройки');
        $second->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $second->getKey());

        self::assertSame('not_configured', $first->fresh()->failure_code);
        self::assertSame('not_configured', $second->fresh()->failure_code);
        self::assertSame(ConversationAutomationState::AiActive, $second->conversation()->firstOrFail()->automation_state);
        self::assertSame(0, CompanionEscalation::query()->count());
        self::assertSame(2, ScenarioEvent::query()->where('event_name', ScenarioEventType::CompanionFallbackFailed)->count());
        self::assertSame(0, ScenarioEvent::query()->where('event_name', ScenarioEventType::CompanionRequestedSpecialist)->count());
    }

    public function test_repeated_unavailable_ai_provider_failures_keep_companion_active_without_escalation(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new ProviderUnavailableCompanionEngine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $first = $this->accept('Первый запрос при сбое провайдера');
        $first->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());

        $second = $this->accept('Второй запрос при сбое провайдера');
        $second->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $second->getKey());

        self::assertSame('provider_unavailable', $first->fresh()->failure_code);
        self::assertSame(CompanionTurnStatus::Failed, $second->fresh()->status);
        self::assertSame(ConversationAutomationState::AiActive, $second->conversation()->firstOrFail()->automation_state);
        self::assertSame(0, CompanionEscalation::query()->count());
        self::assertSame(0, ScenarioEvent::query()->where('event_name', 'companion.requested_specialist')->count());
        self::assertSame(2, ScenarioEvent::query()->where('event_name', 'companion.fallback_failed')->count());

        $recoveredEngine = new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'reply',
                'reply' => 'Привет! Я могу помочь.',
                'handoff_reason' => '',
                'suggested_safe_actions' => [],
            ],
        ));
        $this->app->instance(AiWorkflowEngine::class, $recoveredEngine);
        $greeting = $this->accept('привет');
        $greeting->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $greeting->getKey());

        self::assertSame(CompanionTurnStatus::Completed, $greeting->fresh()->status);
        self::assertSame(ConversationAutomationState::AiActive, $greeting->conversation()->firstOrFail()->automation_state);
        self::assertSame('привет', $recoveredEngine->request?->inputVariables['current_message']);
    }

    public function test_retry_reuses_failed_input_with_a_new_attempt_and_preserves_failure_evidence(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new ProviderUnavailableCompanionEngine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('Повторите исходный запрос');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $turn->refresh();
        $failureMessageId = (int) $turn->outbound_message_id;
        $failureMessage = ConversationMessage::query()->findOrFail($failureMessageId);
        self::assertSame('Не получилось подготовить ответ.', app(CompanionMessageBodyReader::class)->read($this->organization->getKey(), $failureMessage));
        self::assertSame(['retry_failed_turn', 'request_human'], array_values(array_filter(explode(',', $failureMessage->metadata['safe_actions']))));
        $firstAttempt = CompanionTurnAttempt::query()->where('turn_id', $turn->getKey())->sole();
        self::assertSame(CompanionTurnAttemptStatus::Failed, $firstAttempt->status);
        self::assertSame('provider_unavailable', $firstAttempt->failure_code);

        $retry = app(RetryCompanionTurn::class);
        self::assertSame(RetryCompanionTurnResult::Queued, $retry->handle($this->client, $failureMessageId));
        self::assertSame(RetryCompanionTurnResult::AlreadyRequested, $retry->handle($this->client, $failureMessageId));
        self::assertSame(CompanionTurnStatus::Pending, $turn->fresh()->status);
        self::assertSame(2, CompanionTurnAttempt::query()->where('turn_id', $turn->getKey())->count());
        self::assertSame(1, ConversationMessage::query()->where('conversation_id', $turn->conversation_id)->where('author_type', 'client')->count());

        $engine = new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'reply',
                'reply' => 'Готово, вот ответ.',
                'handoff_reason' => '',
                'suggested_safe_actions' => [],
            ],
        ));
        $this->app->instance(AiWorkflowEngine::class, $engine);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $turn->refresh();
        $attempts = CompanionTurnAttempt::query()->where('turn_id', $turn->getKey())->orderBy('attempt_number')->get();
        self::assertSame(CompanionTurnStatus::Completed, $turn->status);
        self::assertSame('Повторите исходный запрос', $engine->request?->inputVariables['current_message']);
        self::assertSame('companion-turn:'.$attempts[1]->execution_key, $engine->request?->idempotencyKey);
        self::assertNotSame($attempts[0]->execution_key, $attempts[1]->execution_key);
        self::assertSame(CompanionTurnAttemptStatus::Failed, $attempts[0]->status);
        self::assertSame($failureMessageId, (int) $attempts[0]->output_message_id);
        self::assertSame(CompanionTurnAttemptStatus::Succeeded, $attempts[1]->status);
        self::assertSame(2, ConversationMessage::query()->where('conversation_id', $turn->conversation_id)->where('author_type', 'ai')->count());
        self::assertSame(1, ConversationMessage::query()->where('conversation_id', $turn->conversation_id)->where('author_type', 'client')->count());
        self::assertNotSame($failureMessageId, (int) $turn->outbound_message_id);
        self::assertSame(2, CompanionDelivery::query()->whereIn('conversation_message_id', [$failureMessageId, $turn->outbound_message_id])->count());
        self::assertSame(1, CompanionDelivery::query()->where('conversation_message_id', $failureMessageId)->where('turn_id', $turn->getKey())->count());
        self::assertSame(1, CompanionDelivery::query()->where('conversation_message_id', $turn->outbound_message_id)->whereNull('turn_id')->count());
    }

    public function test_retryable_telegram_failure_delivers_a_retry_button_for_its_failed_message(): void
    {
        $channel = new RecordingCompanionChannel;
        $this->app->instance(AiWorkflowEngine::class, new ProviderUnavailableCompanionEngine);
        $this->app->instance(MessagingChannel::class, $channel);

        $turn = $this->accept('Не удалось ответить');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());
        $failureMessageId = (int) $turn->fresh()->outbound_message_id;
        $delivery = CompanionDelivery::query()->where('conversation_message_id', $failureMessageId)->sole();

        (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(['Повторить', 'Позвать специалиста'], array_map(
            static fn ($button): string => $button->text,
            $channel->chunks[0]->buttons,
        ));
        self::assertSame('cc:retry:'.$failureMessageId, $channel->chunks[0]->buttons[0]->callbackData);
        self::assertSame('cc:human:'.$failureMessageId, $channel->chunks[0]->buttons[1]->callbackData);
    }

    public function test_portal_retry_uses_the_same_application_action_and_keeps_one_client_message(): void
    {
        config()->set('tenancy.default_organization_id', $this->organization->getKey());
        $this->app->instance(AiWorkflowEngine::class, new ProviderUnavailableCompanionEngine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('Повторите сообщение из портала');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());
        $failureMessageId = (int) $turn->fresh()->outbound_message_id;
        $portalSession = ['client_portal.client_id' => $this->client->getKey()];

        $this->withSession($portalSession)
            ->get(route('portal.companion'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Portal/Companion')
                ->where('urls.retry', route('portal.companion.retry', ['messageId' => '__id__']))
                ->where('companion.messages.1.safeActions', ['retry_failed_turn', 'request_human']));

        $this->withSession($portalSession)
            ->post(route('portal.companion.retry', ['messageId' => $failureMessageId]))
            ->assertRedirect()
            ->assertSessionHas('companion_retry_result', 'accepted');

        self::assertSame(CompanionTurnStatus::Pending, $turn->fresh()->status);
        self::assertSame(2, CompanionTurnAttempt::query()->where('turn_id', $turn->getKey())->count());
        self::assertSame(1, ConversationMessage::query()
            ->where('conversation_id', $turn->conversation_id)
            ->where('author_type', 'client')
            ->count());
    }

    public function test_portal_specialist_request_keeps_ai_active_and_returns_visible_result(): void
    {
        config()->set('tenancy.default_organization_id', $this->organization->getKey());
        $this->app->instance(AiWorkflowEngine::class, new ProviderUnavailableCompanionEngine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('Позову специалиста, если понадобится');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());
        $failureMessageId = (int) $turn->fresh()->outbound_message_id;

        $this->withSession(['client_portal.client_id' => $this->client->getKey()])
            ->post(route('portal.companion.specialist', ['messageId' => $failureMessageId]))
            ->assertRedirect()
            ->assertSessionHas('companion_specialist_requested', 'created');

        self::assertSame(ConversationAutomationState::AiActive, $turn->conversation()->firstOrFail()->automation_state);
        self::assertSame(CompanionEscalationReason::HumanRequested, CompanionEscalation::query()->sole()->reason);
    }

    public function test_retry_rejects_another_clients_message_and_a_stale_context_epoch(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new ProviderUnavailableCompanionEngine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('Проверка защиты повтора');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());
        $failureMessageId = (int) $turn->fresh()->outbound_message_id;

        $otherClient = Client::factory()->forOrganization($this->organization)->create();
        try {
            app(RetryCompanionTurn::class)->handle($otherClient, $failureMessageId);
            self::fail('A different client must not retry this turn.');
        } catch (AuthorizationException) {
        }

        $turn->conversation()->firstOrFail()->update(['context_epoch' => $turn->context_epoch + 1]);
        self::assertSame(
            RetryCompanionTurnResult::Unavailable,
            app(RetryCompanionTurn::class)->handle($this->client, $failureMessageId),
        );
        self::assertSame(CompanionTurnStatus::Failed, $turn->fresh()->status);
        self::assertSame(1, CompanionTurnAttempt::query()->where('turn_id', $turn->getKey())->count());
    }

    public function test_retry_from_before_a_real_human_takeover_is_unavailable_after_resume(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new ProviderUnavailableCompanionEngine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('Сбой перед подключением специалиста');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());
        $failureMessageId = (int) $turn->fresh()->outbound_message_id;
        $admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();

        app(TakeOverCompanionConversation::class)->handle($admin, $this->client);
        app(ResolveCompanionHandoff::class)->handleAndResume($admin, $this->client);

        self::assertSame(
            RetryCompanionTurnResult::Unavailable,
            app(RetryCompanionTurn::class)->handle($this->client, $failureMessageId),
        );
        $history = app(ReadCompanionConversation::class)->forClient($this->client);
        $failure = collect($history['messages'])->firstWhere('id', $failureMessageId);
        self::assertNotContains('retry_failed_turn', $failure['safeActions']);
    }

    public function test_verified_legacy_technical_handoff_can_be_restored_without_replaying_paused_messages(): void
    {
        $failedTurn = $this->accept('Сообщение до автоматического handoff');
        $conversation = $failedTurn->conversation()->firstOrFail();
        $failedTurn->update([
            'status' => CompanionTurnStatus::Failed,
            'failure_code' => 'provider_unavailable',
            'failed_at' => now(),
        ]);
        $conversation->update(['automation_state' => ConversationAutomationState::HumanHandoff]);
        $escalation = CompanionEscalation::query()->create([
            'organization_id' => $this->organization->getKey(),
            'client_id' => $this->client->getKey(),
            'conversation_id' => $conversation->getKey(),
            'turn_id' => $failedTurn->getKey(),
            'reason' => CompanionEscalationReason::RepeatedExecutionFailure,
            'status' => CompanionEscalationStatus::Open,
            'safe_metadata' => ['source' => 'legacy_failure'],
            'opened_at' => now(),
        ]);
        $pausedTurn = $this->accept('Сообщение, принятое во время старой паузы');
        $admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();

        self::assertSame(CompanionTurnStatus::Paused, $pausedTurn->fresh()->status);
        self::assertTrue(app(ReadCompanionConversation::class)->forStaff($admin, $this->client)['canRemediateLegacyHandoff']);
        self::assertTrue(app(RestoreLegacyCompanionAi::class)->handle($admin, $this->client));

        self::assertSame(ConversationAutomationState::AiActive, $conversation->fresh()->automation_state);
        self::assertSame(CompanionEscalationStatus::Resolved, $escalation->fresh()->status);
        self::assertSame(CompanionTurnStatus::Cancelled, $pausedTurn->fresh()->status);
        self::assertDatabaseHas('audit_events', [
            'organization_id' => $this->organization->getKey(),
            'action' => 'companion.handoff.legacy_failure_remediated',
            'target_id' => (string) $conversation->getKey(),
        ]);
    }

    public function test_legacy_restore_rejects_a_handoff_with_a_real_request_for_specialist(): void
    {
        $turn = $this->accept('Запрос специалисту');
        $conversation = $turn->conversation()->firstOrFail();
        $conversation->update(['automation_state' => ConversationAutomationState::HumanHandoff]);
        CompanionEscalation::query()->create([
            'organization_id' => $this->organization->getKey(),
            'client_id' => $this->client->getKey(),
            'conversation_id' => $conversation->getKey(),
            'turn_id' => $turn->getKey(),
            'reason' => CompanionEscalationReason::HumanRequested,
            'status' => CompanionEscalationStatus::Open,
            'safe_metadata' => ['source' => 'client_action'],
            'opened_at' => now(),
        ]);
        $admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();

        self::assertFalse(app(RestoreLegacyCompanionAi::class)->handle($admin, $this->client));
        self::assertSame(ConversationAutomationState::HumanHandoff, $conversation->fresh()->automation_state);
        self::assertSame(CompanionEscalationStatus::Open, CompanionEscalation::query()->sole()->status);
    }

    public function test_ambiguous_legacy_pause_requires_explicit_takeover_before_staff_reply(): void
    {
        $turn = $this->accept('Старое состояние паузы');
        $conversation = $turn->conversation()->firstOrFail();
        $conversation->update(['automation_state' => ConversationAutomationState::HumanHandoff]);
        $admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();

        $history = app(ReadCompanionConversation::class)->forStaff($admin, $this->client);
        self::assertSame('handoff_paused', $history['mode']);
        self::assertFalse($history['humanTakeoverConfirmed']);

        try {
            app(ReplyToCompanion::class)->handle($admin, $this->client, 'Ответ до подтверждения takeover');
            self::fail('An ambiguous legacy pause must not accept a specialist reply.');
        } catch (ValidationException) {
        }

        app(TakeOverCompanionConversation::class)->handle($admin, $this->client);

        self::assertNotNull($conversation->fresh()->last_human_takeover_at);
        app(ReplyToCompanion::class)->handle($admin, $this->client, 'Ответ после подтверждения takeover');
        self::assertSame(1, ConversationMessage::query()->where('conversation_id', $conversation->getKey())->where('author_type', 'staff')->count());
    }

    public function test_repeated_provider_failure_for_a_negated_human_request_does_not_create_a_handoff(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new ProviderUnavailableCompanionEngine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $first = $this->accept('мне не нужен специалист');
        $first->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());

        $second = $this->accept('привет, не надо мне специалиста');
        $second->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $second->getKey());

        self::assertSame(CompanionTurnStatus::Failed, $second->fresh()->status);
        self::assertSame(ConversationAutomationState::AiActive, $second->conversation()->firstOrFail()->automation_state);
        self::assertSame(0, CompanionEscalation::query()->count());
        self::assertSame(0, ScenarioEvent::query()->where('event_name', 'companion.requested_specialist')->count());
    }

    public function test_failed_ai_run_result_keeps_provider_failure_category(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Failed,
            errorCategory: AiErrorCategory::ProviderUnavailable,
        )));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('Проверьте ответ при сбое провайдера');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Failed, $turn->fresh()->status);
        self::assertSame('provider_unavailable', $turn->fresh()->failure_code);
        self::assertSame('companion.fallback_failed', ScenarioEvent::query()->sole()->event_name->value);
        self::assertSame($turn->getKey(), ScenarioEvent::query()->sole()->payload['turn_id']);
    }

    public function test_missing_active_companion_prompt_is_logged_as_configuration_failure_without_protected_data(): void
    {
        Log::spy();
        $this->app->instance(AiWorkflowEngine::class, new ThrowingInterleavingEngine(
            fn (): null => null,
            'AI execution requires a tenant-owned active prompt version.',
        ));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $turn = $this->accept('Проверка настроек компаньона');
        $turn->update(['burst_expires_at' => now()->subSecond()]);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Failed, $turn->fresh()->status);
        self::assertSame('not_configured', $turn->fresh()->failure_code);
        Log::shouldHaveReceived('warning')
            ->with('client_companion_ai_failure', Mockery::on(function (array $context) use ($turn): bool {
                return $context['organization_id'] === $this->organization->getKey()
                    && $context['companion_turn_id'] === $turn->getKey()
                    && $context['ai_run_id'] === null
                    && $context['prompt_version_id'] === null
                    && $context['failure_code'] === 'not_configured'
                    && ! array_key_exists('client_id', $context)
                    && ! array_key_exists('exception_message', $context)
                    && ! array_key_exists('provider_response', $context);
            }))
            ->once();
    }

    public function test_direct_human_request_notifies_specialist_without_pausing_ai(): void
    {
        $engine = new RecordingCompanionEngine(new AiRunResult(runId: 0, status: AiRunStatus::Succeeded));
        $channel = new RecordingCompanionChannel;
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, $channel);

        $turn = $this->accept('Мне нужен специалист');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $turn->refresh();
        self::assertSame(CompanionTurnStatus::Completed, $turn->status);
        self::assertSame(0, $engine->calls);
        self::assertSame(CompanionEscalationReason::HumanRequested, CompanionEscalation::query()->sole()->reason);
        self::assertSame(ConversationAutomationState::AiActive, $turn->conversation()->firstOrFail()->automation_state);
        self::assertFalse($turn->typing_active);
        self::assertSame('Специалист уведомлён. Пока он не подключился, помощник продолжит отвечать.', app(CompanionMessageBodyReader::class)->read(
            $this->organization->getKey(),
            ConversationMessage::query()->findOrFail($turn->outbound_message_id),
        ));
    }

    public function test_urgent_safety_escalation_uses_attention_event_without_claiming_client_requested_a_specialist(): void
    {
        $channel = new RecordingCompanionChannel;
        $this->app->instance(MessagingChannel::class, $channel);

        $turn = $this->accept('Сильная боль в груди, трудно дышать.');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $turn->refresh();
        $event = ScenarioEvent::query()->sole();

        self::assertSame(CompanionTurnStatus::Completed, $turn->status);
        self::assertSame(ConversationAutomationState::AiActive, $turn->conversation()->firstOrFail()->automation_state);
        self::assertSame(CompanionEscalationReason::UrgentSafetyConcern, CompanionEscalation::query()->sole()->reason);
        self::assertSame(ScenarioEventType::CompanionSpecialistAttention, $event->event_name);
        self::assertSame(CompanionEscalationReason::UrgentSafetyConcern->value, $event->payload['reason']);
        self::assertArrayNotHasKey('message', $event->payload);
        self::assertSame(0, ScenarioEvent::query()->where('event_name', ScenarioEventType::CompanionRequestedSpecialist)->count());
    }

    public function test_explicit_specialist_request_is_recorded_even_while_a_safety_escalation_is_open(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new ProviderUnavailableCompanionEngine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('Не удалось подготовить ответ');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());
        $failureMessage = ConversationMessage::query()->findOrFail($turn->fresh()->outbound_message_id);
        CompanionEscalation::query()->create([
            'organization_id' => $this->organization->getKey(),
            'client_id' => $this->client->getKey(),
            'conversation_id' => $turn->conversation_id,
            'turn_id' => $turn->getKey(),
            'reason' => CompanionEscalationReason::UrgentSafetyConcern,
            'status' => CompanionEscalationStatus::Open,
            'safe_metadata' => ['source' => 'safety_classifier'],
            'opened_at' => now(),
        ]);

        self::assertSame(RequestCompanionHandoffResult::Created, app(RequestCompanionHandoff::class)->handle(
            $this->client,
            (int) $failureMessage->getKey(),
        ));

        self::assertSame(2, CompanionEscalation::query()->where('status', CompanionEscalationStatus::Open)->count());
        self::assertSame(1, CompanionEscalation::query()->where('reason', CompanionEscalationReason::HumanRequested)->count());
        self::assertSame(ConversationAutomationState::AiActive, $turn->conversation()->firstOrFail()->automation_state);
        self::assertCount(2, collect(app(ReadCompanionConversation::class)->forClient($this->client)['messages'])
            ->where('type', 'handoff'));
    }

    public function test_model_cannot_infer_human_requested_when_the_client_did_not_explicitly_request_it(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'handoff_required',
                'reply' => '',
                'handoff_reason' => 'human_requested',
                'suggested_safe_actions' => [],
            ],
        )));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('Привет, ты кто?');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Failed, $turn->fresh()->status);
        self::assertSame('invalid_output', $turn->fresh()->failure_code);
        self::assertSame(ConversationAutomationState::AiActive, $turn->conversation()->firstOrFail()->automation_state);
        self::assertSame(0, CompanionEscalation::query()->count());
    }

    public function test_explicit_negated_human_request_cannot_be_escalated_by_a_model_handoff_decision(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'handoff_required',
                'reply' => 'Хорошо, я продолжу помогать сам.',
                'handoff_reason' => 'out_of_scope',
                'suggested_safe_actions' => [],
            ],
        )));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('привет, не надо мне специалиста');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Completed, $turn->fresh()->status);
        self::assertSame(ConversationAutomationState::AiActive, $turn->conversation()->firstOrFail()->automation_state);
        self::assertSame(0, CompanionEscalation::query()->count());
        self::assertSame(0, ScenarioEvent::query()->count());
    }

    public function test_routine_greeting_cannot_be_escalated_by_a_model_handoff_decision(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'handoff_required',
                'reply' => 'Привет! Я помогу сориентироваться.',
                'handoff_reason' => 'out_of_scope',
                'suggested_safe_actions' => [],
            ],
        )));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('Привет, ты кто?');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Completed, $turn->fresh()->status);
        self::assertSame(ConversationAutomationState::AiActive, $turn->conversation()->firstOrFail()->automation_state);
        self::assertSame(0, CompanionEscalation::query()->count());
        self::assertSame(0, ScenarioEvent::query()->count());
    }

    public function test_out_of_scope_reply_stays_usable_without_escalation_or_handoff(): void
    {
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'handoff_required',
                'reply' => '',
                'handoff_reason' => 'out_of_scope',
                'suggested_safe_actions' => [],
            ],
        )));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        $turn = $this->accept('у меня анапластическая эпендимома и на уровне Th12-L1 у меня его вырезали grade 3 WHO');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Completed, $turn->fresh()->status);
        self::assertSame(ConversationAutomationState::AiActive, $turn->conversation()->firstOrFail()->automation_state);
        self::assertSame(0, CompanionEscalation::query()->count());
        self::assertContains('request_human', explode(',', ConversationMessage::query()->findOrFail($turn->fresh()->outbound_message_id)->metadata['safe_actions'] ?? ''));
    }

    public function test_staff_takeover_and_resume_then_normal_greeting_produces_an_ai_reply(): void
    {
        $admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();
        $engine = new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'reply',
                'reply' => 'Привет! Я снова на связи.',
                'handoff_reason' => '',
                'suggested_safe_actions' => [],
            ],
        ));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $this->app->instance(AiWorkflowEngine::class, $engine);

        $handoff = $this->accept('Мне нужен специалист');
        $handoff->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $handoff->getKey());

        self::assertSame(ConversationAutomationState::AiActive, $handoff->conversation()->firstOrFail()->automation_state);

        $aiContinues = $this->accept('привет');
        $aiContinues->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $aiContinues->getKey());
        self::assertSame(CompanionTurnStatus::Completed, $aiContinues->fresh()->status);
        self::assertSame(1, $engine->calls);
        self::assertSame(1, CompanionEscalation::query()->where('status', 'open')->count());

        app(TakeOverCompanionConversation::class)->handle($admin, $this->client);
        $takeoverNotice = ConversationMessage::query()
            ->where('conversation_id', $handoff->conversation_id)
            ->where('author_type', 'system')
            ->where('metadata->message_type', 'human_takeover')
            ->sole();
        self::assertSame(
            'К диалогу подключился специалист. AI-помощник временно не отвечает.',
            app(CompanionMessageBodyReader::class)->read($this->organization->getKey(), $takeoverNotice),
        );
        app(TakeOverCompanionConversation::class)->handle($admin, $this->client);
        self::assertSame(1, ConversationMessage::query()
            ->where('conversation_id', $handoff->conversation_id)
            ->where('metadata->message_type', 'human_takeover')
            ->count());
        self::assertSame(1, CompanionDelivery::query()
            ->where('conversation_message_id', $takeoverNotice->getKey())
            ->count());
        self::assertSame(ConversationAutomationState::HumanHandoff, $handoff->conversation()->firstOrFail()->automation_state);
        self::assertNotNull($handoff->conversation()->firstOrFail()->last_human_takeover_at);

        $paused = $this->accept('Новое сообщение специалисту');
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $paused->getKey());
        self::assertSame(CompanionTurnStatus::Paused, $paused->fresh()->status);
        self::assertSame(1, $engine->calls);

        app(ReplyToCompanion::class)->handle($admin, $this->client, 'Я подключился к диалогу.');
        self::assertSame(1, ConversationMessage::query()->where('conversation_id', $handoff->conversation_id)->where('author_type', 'staff')->count());

        app(ResolveCompanionHandoff::class)->handleAndResume($admin, $this->client);
        self::assertSame(CompanionTurnStatus::Cancelled, $paused->fresh()->status);
        self::assertSame(0, CompanionEscalation::query()->where('status', 'open')->count());

        $greeting = $this->accept('привет');
        $greeting->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $greeting->getKey());

        $greeting->refresh();
        self::assertSame(CompanionTurnStatus::Completed, $greeting->status);
        self::assertSame(ConversationAutomationState::AiActive, $greeting->conversation()->firstOrFail()->automation_state);
        self::assertSame('Привет! Я снова на связи.', app(CompanionMessageBodyReader::class)->read(
            $this->organization->getKey(),
            ConversationMessage::query()->findOrFail($greeting->outbound_message_id),
        ));
        self::assertSame(1, CompanionEscalation::query()->count());
        self::assertSame(1, ScenarioEvent::query()->where('event_name', 'companion.requested_specialist')->count());
        self::assertSame(2, $engine->calls);
    }

    public function test_only_staff_with_companion_handoff_permission_can_take_over(): void
    {
        $turn = $this->accept('Запрос на проверку разрешений');
        $unauthorized = User::factory()->create();

        try {
            app(TakeOverCompanionConversation::class)->handle($unauthorized, $this->client);
            self::fail('A staff member without Companion permissions must not take over.');
        } catch (AuthorizationException) {
        }

        self::assertSame(ConversationAutomationState::AiActive, $turn->conversation()->firstOrFail()->automation_state);
    }

    public function test_long_reply_delivers_ordered_chunks_with_actions_only_on_the_final_chunk_and_retry_is_idempotent(): void
    {
        $channel = new RecordingCompanionChannel;
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'reply',
                'reply' => str_repeat('Длинный ответ. ', 2500),
                'handoff_reason' => '',
                'suggested_safe_actions' => ['feedback_helpful'],
            ],
        )));
        $this->app->instance(MessagingChannel::class, $channel);

        $turn = $this->accept('Продолжите подробно');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $deliveries = CompanionDelivery::query()->where('turn_id', $turn->getKey())->orderBy('chunk_index')->get();
        self::assertGreaterThan(1, $deliveries->count());
        Queue::assertPushed(DeliverCompanionMessage::class, 1);
        foreach ($deliveries as $delivery) {
            (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
                ->handle($channel, app(CompanionMessageBodyReader::class));
            self::assertSame(CompanionDeliveryStatus::Delivered, $delivery->fresh()->status);
        }

        self::assertCount($deliveries->count(), $channel->chunks);
        $buttonIndexes = array_keys(array_filter($channel->chunks, static fn (CompanionOutboundChunk $chunk): bool => $chunk->buttons !== []));
        self::assertSame([count($channel->chunks) - 1], $buttonIndexes);
        foreach ($deliveries as $delivery) {
            (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
                ->handle($channel, app(CompanionMessageBodyReader::class));
        }
        self::assertCount($deliveries->count(), $channel->chunks);
    }

    public function test_open_portal_action_targets_the_authenticated_telegram_mini_app(): void
    {
        config()->set('portal.telegram.portal_url', 'https://mini.example.test');
        $channel = new RecordingCompanionChannel;
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: [
                'decision' => 'reply',
                'reply' => 'Откройте кабинет.',
                'handoff_reason' => '',
                'suggested_safe_actions' => ['open_portal'],
            ],
        )));
        $this->app->instance(MessagingChannel::class, $channel);

        $turn = $this->accept('Откройте мой кабинет');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());
        $delivery = CompanionDelivery::query()->where('turn_id', $turn->getKey())->sole();

        (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        $button = $channel->chunks[0]->buttons[0];
        self::assertSame(
            'https://mini.example.test'.route('portal.telegram.launch', ['entry' => 'portal'], false),
            $button->webAppUrl,
        );
        self::assertNull($button->url);
    }

    public function test_failed_later_chunk_does_not_replay_already_delivered_chunks(): void
    {
        $deliveries = $this->createDeliveries(str_repeat('Длинный ответ. ', 2500));
        self::assertGreaterThan(1, $deliveries->count());
        $first = $deliveries->firstOrFail();
        $second = $deliveries->get(1);
        $channel = new FailOnceOnSecondChunkCompanionChannel;

        (new DeliverCompanionMessage($this->organization->getKey(), $first->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));
        (new DeliverCompanionMessage($this->organization->getKey(), $second->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Delivered, $first->fresh()->status);
        self::assertSame(CompanionDeliveryStatus::Failed, $second->fresh()->status);
        self::assertSame([0, 1], array_map(static fn (CompanionOutboundChunk $chunk): int => $chunk->chunkIndex, $channel->chunks));

        (new DeliverCompanionMessage($this->organization->getKey(), $first->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));
        self::assertSame([0, 1], array_map(static fn (CompanionOutboundChunk $chunk): int => $chunk->chunkIndex, $channel->chunks));

        $second->update(['next_attempt_at' => now()->subSecond()]);
        (new DeliverCompanionMessage($this->organization->getKey(), $second->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Delivered, $second->fresh()->status);
        self::assertSame([0, 1, 1], array_map(static fn (CompanionOutboundChunk $chunk): int => $chunk->chunkIndex, $channel->chunks));
    }

    public function test_companion_attachment_is_delivered_once_before_text_chunks_continue(): void
    {
        $deliveries = $this->createDeliveries(str_repeat('Длинный ответ. ', 2500));
        self::assertGreaterThan(1, $deliveries->count());
        $first = $deliveries->firstOrFail();
        $message = ConversationMessage::query()->findOrFail($first->conversation_message_id);
        $attachment = MedicalAttachment::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $this->organization->getKey(),
            'client_id' => $this->client->getKey(),
            'attachment_type' => AttachmentType::CompanionDocument,
            'disk' => 'private',
            'storage_path' => 'medical/attachments/'.$this->organization->getKey().'/communication.txt',
            'original_filename' => 'communication.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 20,
            'sha256_checksum' => hash('sha256', 'communication'),
        ]);
        CompanionMessageAttachment::query()->create([
            'organization_id' => $this->organization->getKey(),
            'client_id' => $this->client->getKey(),
            'conversation_id' => $message->conversation_id,
            'turn_id' => $first->turn_id,
            'conversation_message_id' => $message->getKey(),
            'medical_attachment_id' => $attachment->getKey(),
            'source_ordinal' => 1,
            'item_index' => 1,
        ]);
        $stream = fopen('php://memory', 'rb');
        self::assertIsResource($stream);
        $storage = Mockery::mock(AttachmentStorageInterface::class);
        $storage->shouldReceive('readStream')->once()->andReturn($stream);
        $channel = new RecordingCompanionChannel;

        foreach ($deliveries as $delivery) {
            (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
                ->handle($channel, app(CompanionMessageBodyReader::class), $storage);
        }

        self::assertCount($deliveries->count(), $channel->chunks);
        self::assertCount(1, $channel->chunks[0]->mediaItems);
        self::assertSame([], $channel->chunks[1]->mediaItems);
    }

    public function test_stale_completion_cannot_publish_after_a_new_worker_reclaims_the_turn(): void
    {
        $turn = $this->accept('Поздний ответ');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $this->app->instance(AiWorkflowEngine::class, new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Ответ A', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ),
            function () use ($turn): void {
                CompanionTurn::query()->whereKey($turn->getKey())->update([
                    'processing_lease_token' => 'worker-b-token',
                    'processing_lease_expires_at' => now()->addMinutes(5),
                ]);
            },
        ));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Processing, $turn->fresh()->status);
        self::assertSame('worker-b-token', $turn->fresh()->processing_lease_token);
        self::assertNull($turn->fresh()->outbound_message_id);
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->count());
        self::assertSame(0, CompanionDelivery::query()->count());
    }

    public function test_stale_handoff_cannot_escalate_after_lease_replacement(): void
    {
        $turn = $this->accept('Передача специалисту');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $this->app->instance(AiWorkflowEngine::class, new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'handoff_required', 'reply' => 'Ответ', 'handoff_reason' => 'human', 'suggested_safe_actions' => []],
            ),
            fn (): mixed => CompanionTurn::query()->whereKey($turn->getKey())->update([
                'processing_lease_token' => 'worker-b-token',
                'processing_lease_expires_at' => now()->addMinutes(5),
            ]),
        ));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Processing, $turn->fresh()->status);
        self::assertSame(0, CompanionEscalation::query()->count());
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->count());
    }

    public function test_stale_failure_cannot_overwrite_the_reclaiming_worker(): void
    {
        $turn = $this->accept('Ошибка провайдера');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $this->app->instance(AiWorkflowEngine::class, new ThrowingInterleavingEngine(function () use ($turn): void {
            CompanionTurn::query()->whereKey($turn->getKey())->update([
                'processing_lease_token' => 'worker-b-token',
                'processing_lease_expires_at' => now()->addMinutes(5),
            ]);
        }));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Processing, $turn->fresh()->status);
        self::assertNull($turn->fresh()->failure_code);
        self::assertNull($turn->fresh()->outbound_message_id);
    }

    public function test_reclaimed_worker_completes_exactly_one_response(): void
    {
        $turn = $this->accept('Повторная обработка');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $engine = new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Один ответ', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ),
            function () use ($turn): void {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    CompanionTurn::query()->whereKey($turn->getKey())->update([
                        'processing_lease_token' => 'worker-b-token',
                        'processing_lease_expires_at' => now()->subSecond(),
                    ]);
                }
            },
        );
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $processor = app(CompanionTurnProcessor::class);
        $processor->handle($this->organization->getKey(), $turn->getKey());

        $processor->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Completed, $turn->fresh()->status);
        self::assertSame(2, $engine->calls);
        self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
        self::assertSame(1, CompanionDelivery::query()->where('turn_id', $turn->getKey())->count());
    }

    public function test_context_reset_during_processing_cancels_old_epoch_without_output(): void
    {
        $turn = $this->accept('Сброс контекста');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $this->app->instance(AiWorkflowEngine::class, new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Старый ответ', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ),
            function () use ($turn): void {
                Conversation::query()->whereKey($turn->conversation_id)->update(['context_epoch' => 2]);
            },
        ));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Cancelled, $turn->fresh()->status);
        self::assertNull($turn->fresh()->outbound_message_id);
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->count());
    }

    public function test_valid_ai_execution_beyond_the_old_180_second_lease_boundary_keeps_one_owner(): void
    {
        $startedAt = Carbon::create(2026, 8, 24, 12, 0, 0, 'UTC');
        Carbon::setTestNow($startedAt);
        try {
            $engine = new RecordingCompanionEngine(
                new AiRunResult(
                    runId: 0,
                    status: AiRunStatus::Succeeded,
                    outputPayload: ['decision' => 'reply', 'reply' => 'Долгий ответ', 'handoff_reason' => '', 'suggested_safe_actions' => []],
                ),
                function () use ($startedAt): void {
                    Carbon::setTestNow($startedAt->copy()->addSeconds(181));
                },
            );
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $turn = $this->accept('Длинный запуск');
            $turn->update(['burst_expires_at' => $startedAt->copy()->subSecond()]);

            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

            self::assertSame(CompanionTurnStatus::Completed, $turn->fresh()->status);
            self::assertSame(1, $engine->calls);
            self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
            self::assertSame(
                $startedAt->copy()->addSeconds(AiRuntimeLimits::wholeRunSeconds())->getPreciseTimestamp(6),
                $engine->request?->executionDeadlineAt?->getPreciseTimestamp(6),
            );
            self::assertGreaterThan(180, AiRuntimeLimits::companionProcessingLeaseSeconds());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_processing_recovery_after_the_authoritative_deadline_fails_once_without_ai_replay(): void
    {
        $now = Carbon::create(2026, 8, 24, 13, 0, 0, 'UTC');
        Carbon::setTestNow($now);
        try {
            $engine = new RecordingCompanionEngine(new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Не должно выполниться', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ));
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $turn = $this->accept('Просроченный запуск');
            $turn->update([
                'status' => CompanionTurnStatus::Processing,
                'processing_lease_token' => 'dead-worker',
                'processing_lease_expires_at' => $now->copy()->subSecond(),
                'execution_deadline_at' => $now->copy()->subSecond(),
                'burst_expires_at' => null,
            ]);

            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

            $turn->refresh();
            self::assertSame(CompanionTurnStatus::Failed, $turn->status);
            self::assertSame('execution_deadline_exceeded', $turn->failure_code);
            self::assertSame(0, $engine->calls);
            self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
            self::assertNull($turn->processing_lease_token);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_failover_window_beyond_the_old_lease_boundary_keeps_terminal_fencing(): void
    {
        $startedAt = Carbon::create(2026, 8, 24, 14, 0, 0, 'UTC');
        Carbon::setTestNow($startedAt);
        try {
            $engine = new RecordingCompanionEngine(
                new AiRunResult(
                    runId: 0,
                    status: AiRunStatus::Succeeded,
                    outputPayload: ['decision' => 'reply', 'reply' => 'Ответ после failover', 'handoff_reason' => '', 'suggested_safe_actions' => []],
                ),
                function () use ($startedAt): void {
                    Carbon::setTestNow($startedAt->copy()->addSeconds(181));
                },
            );
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $turn = $this->accept('Проверка failover');
            $turn->update(['burst_expires_at' => $startedAt->copy()->subSecond()]);

            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

            self::assertSame(CompanionTurnStatus::Completed, $turn->fresh()->status);
            self::assertSame(1, $engine->calls);
            self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_human_handoff_during_processing_prevents_late_ai_output(): void
    {
        $admin = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();
        $turn = $this->accept('Пауза специалистом');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        $this->app->instance(AiWorkflowEngine::class, new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Не публиковать', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ),
            function () use ($admin): void {
                app(TakeOverCompanionConversation::class)->handle($admin, $this->client);
            },
        ));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Paused, $turn->fresh()->status);
        self::assertNull($turn->fresh()->outbound_message_id);
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->count());
        self::assertSame(CompanionTurnAttemptStatus::Cancelled, CompanionTurnAttempt::query()->sole()->status);
    }

    public function test_explicit_retryable_delivery_failure_is_bounded_and_persisted(): void
    {
        $delivery = $this->createDeliveries('Повторить доставку')->sole();
        $channel = new RetryableCompanionChannel;

        (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        $delivery->refresh();
        self::assertSame(CompanionDeliveryStatus::Failed, $delivery->status);
        self::assertSame('provider_rejected_before_acceptance', $delivery->last_error_code);
        self::assertNotNull($delivery->next_attempt_at);
        self::assertCount(1, $channel->chunks);
    }

    public function test_external_side_effect_followed_by_exception_becomes_uncertain_without_replay(): void
    {
        $delivery = $this->createDeliveries('Не дублировать после сбоя')->sole();
        $channel = new CrashAfterExternalSideEffectCompanionChannel;
        $job = new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey());

        $job->handle($channel, app(CompanionMessageBodyReader::class));
        $job->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Uncertain, $delivery->fresh()->status);
        self::assertSame('delivery_send_exception_unknown', $delivery->fresh()->last_error_code);
        self::assertCount(1, $channel->chunks);
    }

    public function test_generic_unknown_without_error_code_uses_safe_fallback_without_replay(): void
    {
        $delivery = $this->createDeliveries('Не дублировать неизвестный результат')->sole();
        $channel = new FixedOutcomeCompanionChannel(NotificationDeliveryOutcome::Unknown);
        $job = new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey());

        $job->handle($channel, app(CompanionMessageBodyReader::class));
        $job->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Uncertain, $delivery->fresh()->status);
        self::assertSame('delivery_outcome_unknown', $delivery->fresh()->last_error_code);
        self::assertCount(1, $channel->chunks);
    }

    public function test_in_flight_delivery_result_is_coerced_to_unknown_with_safe_fallback(): void
    {
        $delivery = $this->createDeliveries('Сохранить неизвестный результат')->sole();
        $channel = new FixedOutcomeCompanionChannel(NotificationDeliveryOutcome::InFlight);

        (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Uncertain, $delivery->fresh()->status);
        self::assertSame('delivery_outcome_unknown', $delivery->fresh()->last_error_code);
        self::assertCount(1, $channel->chunks);
    }

    public function test_expired_delivery_lease_becomes_uncertain_without_replaying_the_provider_call(): void
    {
        $delivery = $this->createDeliveries('Не повторять неизвестную отправку')->sole();
        $delivery->update([
            'status' => CompanionDeliveryStatus::Processing,
            'processing_lease_token' => 'dead-worker',
            'processing_lease_expires_at' => now()->subSecond(),
        ]);
        $channel = new RecordingCompanionChannel;
        $channel->chunks[] = new CompanionOutboundChunk('chat-1', 'side effect already happened', 0, 1, 'ru');

        (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Uncertain, $delivery->fresh()->status);
        self::assertSame('delivery_lease_expired_unknown', $delivery->fresh()->last_error_code);
        self::assertCount(1, $channel->chunks);
    }

    public function test_stale_delivery_worker_cannot_finalize_over_a_new_lease(): void
    {
        $delivery = $this->createDeliveries('Фехтование доставки')->sole();
        $channel = new LeaseReplacingCompanionChannel($delivery->getKey());

        (new DeliverCompanionMessage($this->organization->getKey(), $delivery->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Processing, $delivery->fresh()->status);
        self::assertSame('new-delivery-worker', $delivery->fresh()->processing_lease_token);
        self::assertCount(1, $channel->chunks);
    }

    public function test_uncertain_first_chunk_blocks_later_chunks_without_replaying_any_chunk(): void
    {
        $deliveries = $this->createDeliveries(str_repeat('Длинный ответ. ', 2500));
        self::assertGreaterThan(1, $deliveries->count());
        $first = $deliveries->firstOrFail();
        $first->update([
            'status' => CompanionDeliveryStatus::Processing,
            'processing_lease_token' => 'dead-worker',
            'processing_lease_expires_at' => now()->subSecond(),
        ]);
        $channel = new RecordingCompanionChannel;

        (new DeliverCompanionMessage($this->organization->getKey(), $first->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));
        $second = $deliveries->get(1)->fresh();
        (new DeliverCompanionMessage($this->organization->getKey(), $second->getKey()))
            ->handle($channel, app(CompanionMessageBodyReader::class));

        self::assertSame(CompanionDeliveryStatus::Uncertain, $first->fresh()->status);
        self::assertSame(CompanionDeliveryStatus::Failed, $second->fresh()->status);
        self::assertSame('blocked_by_previous_delivery', $second->fresh()->last_error_code);
        self::assertCount(0, $channel->chunks);
    }

    /** @return Collection<int, CompanionDelivery> */
    private function createDeliveries(string $reply): Collection
    {
        $this->app->instance(AiWorkflowEngine::class, new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: ['decision' => 'reply', 'reply' => $reply, 'handoff_reason' => '', 'suggested_safe_actions' => []],
        )));
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $turn = $this->accept('Доставить ответ');
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        return CompanionDelivery::query()->where('turn_id', $turn->getKey())->orderBy('chunk_index')->get();
    }

    public function test_media_group_stays_assembling_when_worker_runs_before_the_quiet_window(): void
    {
        $engine = new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: ['decision' => 'reply', 'reply' => 'Фото принято', 'handoff_reason' => '', 'suggested_safe_actions' => []],
        ));
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $first = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'альбом 1',
            idempotencyKey: null,
            originExternalId: 'album-race:1',
            transportChatId: 'album-chat',
            locale: 'ru',
            mediaGroupId: 'album-race',
            sourceOrdinal: 20,
        );

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());
        $second = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'альбом 2',
            idempotencyKey: null,
            originExternalId: 'album-race:2',
            transportChatId: 'album-chat',
            locale: 'ru',
            mediaGroupId: 'album-race',
            sourceOrdinal: 21,
        );

        self::assertSame($first->getKey(), $second->getKey());
        self::assertSame(CompanionTurnStatus::Assembling, $first->fresh()->status);
        self::assertSame(0, $engine->calls);
        self::assertSame(2, CompanionTurnMessage::query()->where('turn_id', $first->getKey())->count());

        $first->refresh()->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());

        self::assertSame(CompanionTurnStatus::Completed, $first->fresh()->status);
        self::assertSame(1, $engine->calls);
        self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
    }

    public function test_album_items_separated_by_more_than_the_old_window_stay_one_turn_until_the_new_quiet_deadline(): void
    {
        $startedAt = Carbon::create(2026, 8, 24, 15, 0, 0, 'UTC');
        Carbon::setTestNow($startedAt);
        try {
            $engine = new RecordingCompanionEngine(new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Весь альбом', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ));
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $first = $this->acceptAlbumItem('album-spacing:1', 'album-spacing', 'первое', 1);

            Carbon::setTestNow($startedAt->copy()->addSeconds(2));
            $second = $this->acceptAlbumItem('album-spacing:2', 'album-spacing', 'второе', 2);

            self::assertSame($first->getKey(), $second->getKey());
            self::assertSame(CompanionTurnStatus::Assembling, $first->fresh()->status);
            self::assertSame(2, CompanionTurnMessage::query()->where('turn_id', $first->getKey())->count());
            self::assertSame(0, $engine->calls);

            Carbon::setTestNow($startedAt->copy()->addSeconds(5));
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());
            self::assertSame(CompanionTurnStatus::Assembling, $first->fresh()->status);
            self::assertSame(0, $engine->calls);

            Carbon::setTestNow($startedAt->copy()->addSeconds(8));
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());

            self::assertSame(CompanionTurnStatus::Completed, $first->fresh()->status);
            self::assertSame(1, $engine->calls);
            self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_album_item_just_before_the_quiet_boundary_refreshes_the_authoritative_deadline(): void
    {
        $startedAt = Carbon::create(2026, 8, 24, 16, 0, 0, 'UTC');
        Carbon::setTestNow($startedAt);
        try {
            $engine = new RecordingCompanionEngine(new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Граница альбома', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ));
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $first = $this->acceptAlbumItem('album-boundary:1', 'album-boundary', 'первое', 1);

            Carbon::setTestNow($startedAt->copy()->addSeconds(4));
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());
            $second = $this->acceptAlbumItem('album-boundary:2', 'album-boundary', 'второе', 2);

            self::assertSame($first->getKey(), $second->getKey());
            self::assertSame(2, CompanionTurnMessage::query()->where('turn_id', $first->getKey())->count());
            self::assertSame(0, $engine->calls);

            Carbon::setTestNow($startedAt->copy()->addSeconds(10));
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $first->getKey());
            self::assertSame(CompanionTurnStatus::Completed, $first->fresh()->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_album_item_during_ai_execution_cancels_the_old_run_before_it_can_publish(): void
    {
        $engine = new InterleavingCompanionEngine(
            new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Неполный ответ', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ),
            function (): void {
                $turn = CompanionTurn::query()->where('media_group_id', 'album-during-run')->sole();
                app(AcceptCompanionMessage::class)->handle(
                    client: $this->client,
                    channel: 'telegram',
                    body: 'позднее фото',
                    idempotencyKey: null,
                    originExternalId: 'album-during-run:2',
                    transportChatId: 'run-chat',
                    locale: 'ru',
                    mediaGroupId: 'album-during-run',
                    sourceOrdinal: 2,
                );
                self::assertSame(CompanionTurnStatus::Cancelled, $turn->fresh()->status);
            },
        );
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $turn = $this->acceptAlbumItem('album-during-run:1', 'album-during-run', 'первое фото', 1);
        $turn->update(['burst_expires_at' => now()->subSecond()]);

        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        self::assertSame(CompanionTurnStatus::Cancelled, $turn->fresh()->status);
        self::assertNotNull($turn->fresh()->album_recovery_message_id);
        self::assertSame(0, ConversationMessage::query()->where('author_type', 'ai')->count());
        self::assertSame(1, $engine->calls);
    }

    public function test_album_hard_assembly_deadline_fails_closed_without_silent_partial_analysis(): void
    {
        config()->set('ai.companion.album_max_assembly_seconds', 5);
        $startedAt = Carbon::create(2026, 8, 24, 17, 0, 0, 'UTC');
        Carbon::setTestNow($startedAt);
        try {
            $engine = new RecordingCompanionEngine(new AiRunResult(
                runId: 0,
                status: AiRunStatus::Succeeded,
                outputPayload: ['decision' => 'reply', 'reply' => 'Не запускать', 'handoff_reason' => '', 'suggested_safe_actions' => []],
            ));
            $this->app->instance(AiWorkflowEngine::class, $engine);
            $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
            $turn = $this->acceptAlbumItem('album-hard-limit:1', 'album-hard-limit', 'первое', 1);

            Carbon::setTestNow($startedAt->copy()->addSeconds(6));
            app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

            $turn->refresh();
            self::assertSame(CompanionTurnStatus::Failed, $turn->status);
            self::assertSame('media_group_incomplete', $turn->failure_code);
            self::assertSame(0, $engine->calls);
            self::assertNotNull($turn->album_recovery_message_id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_late_media_group_item_invalidates_an_incomplete_result_without_repeating_ai(): void
    {
        $engine = new RecordingCompanionEngine(new AiRunResult(
            runId: 0,
            status: AiRunStatus::Succeeded,
            outputPayload: ['decision' => 'reply', 'reply' => 'Один альбом', 'handoff_reason' => '', 'suggested_safe_actions' => []],
        ));
        $this->app->instance(AiWorkflowEngine::class, $engine);
        $this->app->instance(MessagingChannel::class, new RecordingCompanionChannel);
        $turn = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'первый',
            idempotencyKey: null,
            originExternalId: 'album-late:1',
            transportChatId: 'late-chat',
            locale: 'ru',
            mediaGroupId: 'album-late',
            sourceOrdinal: 1,
        );
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());
        $late = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: 'поздний',
            idempotencyKey: null,
            originExternalId: 'album-late:2',
            transportChatId: 'late-chat',
            locale: 'ru',
            mediaGroupId: 'album-late',
            sourceOrdinal: 2,
        );

        self::assertSame($turn->getKey(), $late->getKey());
        self::assertSame(1, $engine->calls);
        self::assertSame(1, CompanionTurn::query()->where('media_group_id', 'album-late')->count());
        self::assertSame(1, CompanionTurnMessage::query()->where('turn_id', $turn->getKey())->count());
        self::assertNotNull($turn->fresh()->album_recovery_message_id);
        self::assertSame(1, ConversationMessage::query()->where('author_type', 'ai')->count());
        self::assertStringContainsString(
            'Фотоальбом получен не полностью',
            app(CompanionMessageBodyReader::class)->read(
                $this->organization->getKey(),
                ConversationMessage::query()->findOrFail($turn->fresh()->album_recovery_message_id),
            ),
        );
        self::assertSame('late_media_group_item', ConversationMessage::query()->where('external_id', 'album-late:2')->firstOrFail()->metadata['ingest_state'] ?? null);
    }

    private function accept(string $body): CompanionTurn
    {
        return app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: $body,
            idempotencyKey: null,
            originExternalId: 'processing-chat:'.CompanionTurn::query()->count().':'.uniqid(),
            transportChatId: 'chat-1',
            locale: 'ru',
        );
    }

    private function acceptAlbumItem(string $externalId, string $mediaGroupId, string $body, int $sourceOrdinal): CompanionTurn
    {
        return app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'telegram',
            body: $body,
            idempotencyKey: null,
            originExternalId: $externalId,
            transportChatId: 'album-chat',
            locale: 'ru',
            mediaGroupId: $mediaGroupId,
            sourceOrdinal: $sourceOrdinal,
        );
    }
}

final class RecordingCompanionEngine implements AiWorkflowEngine
{
    public int $calls = 0;

    public ?AiRunRequest $request = null;

    public function __construct(
        private readonly AiRunResult $result,
        private readonly ?\Closure $beforeReturn = null,
    ) {}

    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        $this->calls++;
        $this->request = $request;
        if ($this->beforeReturn !== null) {
            ($this->beforeReturn)();
        }

        return $this->result;
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        return $this->result;
    }
}

final class InterleavingCompanionEngine implements AiWorkflowEngine
{
    public int $calls = 0;

    public function __construct(
        private readonly AiRunResult $result,
        private readonly \Closure $beforeReturn,
    ) {}

    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        $this->calls++;
        ($this->beforeReturn)();

        return $this->result;
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        return $this->result;
    }
}

final class ThrowingInterleavingEngine implements AiWorkflowEngine
{
    public function __construct(
        private readonly \Closure $beforeThrow,
        private readonly string $message = 'provider unavailable',
    ) {}

    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        ($this->beforeThrow)();
        throw new \RuntimeException($this->message);
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        throw new \RuntimeException($this->message);
    }
}

final class NotConfiguredCompanionEngine implements AiWorkflowEngine
{
    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        throw new InvalidArgumentException('AI execution requires a tenant-owned active prompt version.');
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        throw new AiProviderUnavailableException(
            'No enabled AI provider or model configured for capability.',
            configurationMissing: true,
        );
    }
}

final class InvalidArgumentCompanionEngine implements AiWorkflowEngine
{
    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        throw new InvalidArgumentException('Embedding pricing policy is unavailable for the active configuration.');
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        throw new InvalidArgumentException('Embedding pricing policy is unavailable for the active configuration.');
    }
}

final class ProviderUnavailableCompanionEngine implements AiWorkflowEngine
{
    public function run(int $organizationId, AiRunRequest $request): AiRunResult
    {
        throw new AiProviderUnavailableException('No healthy or enabled AI providers available for requested capability.');
    }

    public function executeRun(int $organizationId, int $runId, string $workerLeaseToken): AiRunResult
    {
        throw new AiProviderUnavailableException('No healthy or enabled AI providers available for requested capability.');
    }
}

class RecordingCompanionChannel implements MessagingChannel
{
    /** @var list<string> */
    public array $typingRecipients = [];

    /** @var list<CompanionOutboundChunk> */
    public array $chunks = [];

    public function name(): string
    {
        return 'telegram';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(true, true, true, true);
    }

    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        $this->chunks[] = $chunk;

        return NotificationDeliveryResult::delivered('fake-message-1');
    }

    public function sendTyping(string $recipientExternalId): bool
    {
        $this->typingRecipients[] = 'telegram:'.$recipientExternalId;

        return true;
    }
}

final class RetryableCompanionChannel extends RecordingCompanionChannel
{
    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        $this->chunks[] = $chunk;

        return NotificationDeliveryResult::retryable('provider_rejected_before_acceptance');
    }
}

final class FailOnceOnSecondChunkCompanionChannel extends RecordingCompanionChannel
{
    private bool $failed = false;

    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        $this->chunks[] = $chunk;
        if ($chunk->chunkIndex === 1 && ! $this->failed) {
            $this->failed = true;

            return NotificationDeliveryResult::retryable('temporary_second_chunk_failure');
        }

        return NotificationDeliveryResult::delivered('fake-message-'.$chunk->chunkIndex);
    }
}

final class CrashAfterExternalSideEffectCompanionChannel extends RecordingCompanionChannel
{
    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        $this->chunks[] = $chunk;
        throw new \RuntimeException('Telegram accepted the message before the worker crashed.');
    }
}

final class FixedOutcomeCompanionChannel extends RecordingCompanionChannel
{
    public function __construct(private readonly NotificationDeliveryOutcome $outcome) {}

    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        $this->chunks[] = $chunk;

        return new NotificationDeliveryResult($this->outcome);
    }
}

final class LeaseReplacingCompanionChannel extends RecordingCompanionChannel
{
    public function __construct(private readonly int $deliveryId) {}

    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        $this->chunks[] = $chunk;
        CompanionDelivery::query()->whereKey($this->deliveryId)->update([
            'processing_lease_token' => 'new-delivery-worker',
            'processing_lease_expires_at' => now()->addMinutes(5),
        ]);

        return NotificationDeliveryResult::delivered('provider-message-1');
    }
}
