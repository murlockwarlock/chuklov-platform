<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Channels\Infrastructure\Database\DatabaseNotificationChannel;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Actions\RequestCompanionHandoff;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\ExecuteScenarioAction;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Application\ScenarioEventPermissionPolicy;
use App\Modules\Scenarios\Domain\Enums\ScenarioDeliveryStatus;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventType;
use App\Modules\Scenarios\Domain\Models\ScenarioAction;
use App\Modules\Scenarios\Domain\Models\ScenarioDelivery;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Scenarios\Jobs\ProcessScenarioEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingNotificationChannel;
use Tests\TestCase;

final class OperationalNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_handoff_uses_durable_crm_and_permission_scoped_telegram_delivery(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Мария Уведомление']);
        OrganizationChannelIdentity::factory()->forUser($admin)->verified()->create(['external_id' => 'admin-chat-1']);
        OrganizationChannelIdentity::factory()->forUser($staff)->verified()->create(['external_id' => 'staff-chat-1']);
        app(OrganizationContext::class)->set($organization);
        config()->set('tenancy.default_organization_id', $organization->getKey());
        Queue::fake();

        $telegram = new RecordingNotificationChannel;
        $this->app->instance(NotificationChannelRegistry::class, new NotificationChannelRegistry([
            app(DatabaseNotificationChannel::class),
            $telegram,
        ]));

        $turn = app(AcceptCompanionMessage::class)->handle(
            client: $client,
            channel: 'portal',
            body: 'Нужен специалист',
            idempotencyKey: 'crm-handoff-notification-01',
            originExternalId: 'portal:handoff-notification-01',
            locale: 'ru',
        );
        self::assertInstanceOf(CompanionTurn::class, $turn);
        $conversation = Conversation::query()->whereKey($turn->conversation_id)->firstOrFail();
        $outbound = app(RecordCompanionMessage::class)->handle(
            organizationId: $organization->getKey(),
            client: $client,
            conversation: $conversation,
            channel: 'portal',
            direction: ConversationDirection::Outbound,
            authorType: ConversationAuthorType::Ai,
            body: 'Передаю обращение специалисту.',
            contextEpoch: $turn->context_epoch,
            metadata: ['message_type' => 'handoff', 'transport' => 'portal'],
        );
        $turn->forceFill([
            'status' => CompanionTurnStatus::Completed,
            'outbound_message_id' => $outbound->getKey(),
        ])->save();
        $conversation->forceFill(['automation_state' => ConversationAutomationState::AiActive])->save();

        app(RequestCompanionHandoff::class)->handle($client, $outbound->getKey());
        $event = ScenarioEvent::query()
            ->where('organization_id', $organization->getKey())
            ->where('event_name', 'companion.requested_specialist')
            ->sole();
        Queue::assertPushed(ProcessScenarioEvent::class, fn (ProcessScenarioEvent $job): bool => $job->scenarioEventId === $event->getKey());

        (new ProcessScenarioEvent($event->getKey()))->handle(app(MaterializeScenarioEvent::class));
        $actions = ScenarioAction::query()->where('scenario_event_id', $event->getKey())->get();
        self::assertCount(2, $actions);

        foreach ($actions as $action) {
            $action->forceFill(['scheduled_for' => now()->subSecond()])->save();
            $action->deliveries()->update(['next_attempt_at' => now()->subSecond()]);
            app(ExecuteScenarioAction::class)->handle($action->getKey());
        }

        self::assertSame(1, $admin->notifications()->count() + $staff->notifications()->count());
        self::assertCount(1, $telegram->messages);
        self::assertInstanceOf(NotificationMessage::class, $telegram->messages[0] ?? null);
        self::assertSame('admin-chat-1', $telegram->messages[0]->recipientExternalId);
        self::assertStringContainsString('Мария Уведомление', $telegram->messages[0]->body);
        self::assertSame(0, $staff->fresh()->notifications()->count());

        $adminTelegram = $actions
            ->first(fn (ScenarioAction $action): bool => $action->recipient_user_id === $admin->getKey()
                && $action->channel_priority === ['telegram']);
        self::assertInstanceOf(ScenarioAction::class, $adminTelegram);
        self::assertSame(ScenarioDeliveryStatus::Delivered, $adminTelegram->deliveries()->sole()->status);
        $adminDatabase = $actions
            ->first(fn (ScenarioAction $action): bool => $action->recipient_user_id === $admin->getKey()
                && $action->channel_priority === ['database']);
        self::assertInstanceOf(ScenarioAction::class, $adminDatabase);
        self::assertSame(ScenarioDeliveryStatus::Delivered, $adminDatabase->deliveries()->sole()->status);
        self::assertSame('processed', $event->fresh()->status->value);
        self::assertSame(2, ScenarioDelivery::query()
            ->whereIn('scenario_action_id', $actions->pluck('id'))
            ->where('status', ScenarioDeliveryStatus::Delivered)
            ->count());

        foreach ($actions as $action) {
            app(ExecuteScenarioAction::class)->handle($action->getKey());
        }

        self::assertSame(1, $admin->fresh()->notifications()->count() + $staff->fresh()->notifications()->count());
        self::assertCount(1, $telegram->messages);
    }

    public function test_handoff_permission_policy_requires_an_active_member_with_the_event_permission(): void
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $staff = User::factory()->forOrganization($organization, OrganizationRole::Staff)->create();
        $inactiveAdmin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        $otherOrganization = Organization::factory()->create();
        $otherAdmin = User::factory()->forOrganization($otherOrganization, OrganizationRole::Administrator)->create();
        $inactiveAdmin->membershipFor($organization)?->forceFill(['is_active' => false])->save();
        $eventType = ScenarioEventType::CompanionRequestedSpecialist;
        $policy = app(ScenarioEventPermissionPolicy::class);
        $adminMembership = $admin->memberships()->where('organization_id', $organization->getKey())->firstOrFail();
        $staffMembership = $staff->memberships()->where('organization_id', $organization->getKey())->firstOrFail();
        $inactiveMembership = $inactiveAdmin->memberships()->where('organization_id', $organization->getKey())->firstOrFail();
        $otherMembership = $otherAdmin->memberships()->where('organization_id', $otherOrganization->getKey())->firstOrFail();

        self::assertTrue($policy->allows($eventType, $organization->getKey(), $adminMembership));
        self::assertFalse($policy->allows($eventType, $organization->getKey(), $staffMembership));
        self::assertFalse($policy->allows($eventType, $organization->getKey(), $inactiveMembership));
        self::assertFalse($policy->allows($eventType, $organization->getKey(), $otherMembership));
    }
}
