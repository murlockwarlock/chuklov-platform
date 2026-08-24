<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Filament\Resources\AiRuns\AiRunResource;
use App\Models\User;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionEscalation;
use App\Modules\ClientCompanion\Domain\Models\CompanionFeedback;
use App\Modules\ClientCompanion\Domain\Models\CompanionMessageAttachment;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

final class ReadCompanionConversation
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly CompanionMessageBodyReader $bodyReader,
    ) {}

    /** @return array<string, mixed> */
    public function forClient(Client $client, int $page = 1, ?int $beforeMessageId = null): array
    {
        $organizationId = $this->context->id();
        $this->assertClientOrganization($client, $organizationId);

        return $this->read($organizationId, $client, null, $page, $beforeMessageId, false);
    }

    /** @return array<string, mixed> */
    public function forStaff(User $actor, Client $client, int $page = 1, ?int $beforeMessageId = null): array
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewCompanionHistory);
        $this->assertClientOrganization($client, (int) $organization->getKey());

        return $this->read((int) $organization->getKey(), $client, $actor, $page, $beforeMessageId, true);
    }

    /** @return array<string, mixed> */
    private function read(int $organizationId, Client $client, ?User $actor, int $page, ?int $beforeMessageId, bool $staff): array
    {
        $conversation = Conversation::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('conversation_type', ConversationType::ClientCompanion)
            ->first();

        if (! $conversation instanceof Conversation) {
            $empty = [
                'messages' => [],
                'hasOlder' => false,
                'nextBeforeMessageId' => null,
                'state' => 'ai_active',
                'stateLabel' => 'AI-помощник активен',
                'pending' => false,
                'canReinspectRecentImages' => false,
                'openEscalation' => null,
            ];
            if ($staff) {
                $empty['conversation'] = null;
            }

            return $empty;
        }

        $pageSize = min(50, max(1, (int) config('ai.companion.history_page_size', 30)));
        $query = ConversationMessage::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversation->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($beforeMessageId !== null) {
            $cursor = ConversationMessage::query()
                ->where('organization_id', $organizationId)
                ->where('conversation_id', $conversation->getKey())
                ->whereKey($beforeMessageId)
                ->first();
            if ($cursor instanceof ConversationMessage) {
                $query->where(function (Builder $builder) use ($cursor): void {
                    $builder
                        ->where('occurred_at', '<', $cursor->occurred_at)
                        ->orWhere(function (Builder $nested) use ($cursor): void {
                            $nested->where('occurred_at', $cursor->occurred_at)->where('id', '<', $cursor->getKey());
                        });
                });
            }
        }

        $messages = $query->limit($pageSize + 1)->get();
        $hasOlder = $messages->count() > $pageSize;
        $messages = $messages->take($pageSize)->reverse()->values();
        $messageIds = $messages->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
        $uncertainMessageIds = $staff && $messageIds !== []
            ? CompanionDelivery::query()
                ->where('organization_id', $organizationId)
                ->whereIn('conversation_message_id', $messageIds)
                ->where('status', CompanionDeliveryStatus::Uncertain->value)
                ->pluck('conversation_message_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->flip()
            : collect();
        $attachmentCounts = $messageIds === [] ? collect() : CompanionMessageAttachment::query()
            ->where('organization_id', $organizationId)
            ->whereIn('conversation_message_id', $messageIds)
            ->selectRaw('conversation_message_id, COUNT(*) as attachment_count')
            ->groupBy('conversation_message_id')
            ->pluck('attachment_count', 'conversation_message_id');

        $turns = CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversation->getKey())
            ->where(function (Builder $builder) use ($messageIds): void {
                $builder->whereIn('inbound_message_id', $messageIds)->orWhereIn('outbound_message_id', $messageIds);
            })
            ->get();
        $turnByMessage = [];
        foreach ($turns as $turn) {
            $turnByMessage[(int) $turn->inbound_message_id] = $turn;
            if ($turn->outbound_message_id !== null) {
                $turnByMessage[(int) $turn->outbound_message_id] = $turn;
            }
        }

        $feedback = CompanionFeedback::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->whereIn('message_id', $messageIds)
            ->get()
            ->keyBy('message_id');
        $escalations = $turns->isEmpty()
            ? collect()
            : CompanionEscalation::query()
                ->where('organization_id', $organizationId)
                ->whereIn('turn_id', $turns->modelKeys())
                ->get()
                ->keyBy('turn_id');
        $traceAllowed = $staff && $actor instanceof User && $this->authorizer->allows(
            $actor,
            $this->context->organization(),
            OrganizationPermission::ViewAiTrace,
        );

        $timeline = [];
        foreach ($messages as $message) {
            $turn = $turnByMessage[(int) $message->getKey()] ?? null;
            $feedbackForMessage = $feedback->get($message->getKey());
            $timeline[] = [
                'type' => 'message',
                'id' => $message->getKey(),
                'role' => $message->author_type->value,
                'roleLabel' => $this->roleLabel($message->author_type),
                'content' => $this->bodyReader->read($organizationId, $message),
                'occurredAt' => $message->occurred_at?->toIso8601String() ?? $message->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'transport' => $message->channel === 'telegram' ? 'telegram' : 'portal',
                'transportLabel' => $message->channel === 'telegram' ? 'Telegram' : 'Портал',
                'feedback' => $feedbackForMessage?->value?->value,
                'attachmentCount' => (int) ($attachmentCounts->get($message->getKey()) ?? 0),
                'deliveryNotice' => $uncertainMessageIds->has($message->getKey()) ? [
                    'title' => 'Доставка в Telegram не подтверждена',
                    'body' => 'Telegram не подтвердил результат отправки. Сообщение могло быть доставлено, поэтому система не отправляет его повторно автоматически. Если ответа нет, свяжитесь с клиентом другим способом.',
                ] : null,
                'traceUrl' => $traceAllowed && $message->author_type === ConversationAuthorType::Ai && $turn?->ai_run_id !== null
                    ? AiRunResource::getUrl('view', ['record' => $turn->ai_run_id])
                    : null,
            ];

            if ($turn !== null && $escalations->has($turn->getKey()) && $message->getKey() === $turn->inbound_message_id) {
                $escalation = $escalations->get($turn->getKey());
                $timeline[] = [
                    'type' => 'handoff',
                    'id' => 'handoff-'.$escalation->getKey(),
                    'role' => 'system',
                    'roleLabel' => 'Состояние общения',
                    'content' => $escalation->reasonLabel(),
                    'occurredAt' => $escalation->opened_at->toIso8601String(),
                    'transport' => null,
                    'transportLabel' => null,
                    'feedback' => null,
                    'attachmentCount' => 0,
                    'deliveryNotice' => null,
                    'traceUrl' => null,
                ];
            }
        }

        usort($timeline, static fn (array $left, array $right): int => [$left['occurredAt'], (string) $left['id']]
            <=> [$right['occurredAt'], (string) $right['id']]);

        $oldestMessage = $messages->first();
        $openEscalation = CompanionEscalation::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversation->getKey())
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
        $pending = CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversation->getKey())
            ->whereIn('status', ['assembling', 'pending', 'processing'])
            ->exists();
        $canReinspectRecentImages = CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversation->getKey())
            ->where('context_epoch', $conversation->context_epoch)
            ->where('status', 'completed')
            ->where('input_modality', 'image')
            ->where('completed_at', '>=', now()->subMinutes(max(1, (int) config('ai.companion.recent_image_max_age_minutes', 1440))))
            ->whereHas('attachments')
            ->exists();

        $result = [
            'messages' => $timeline,
            'hasOlder' => $hasOlder,
            'nextBeforeMessageId' => $hasOlder ? $oldestMessage?->getKey() : null,
            'state' => $conversation->automation_state->value,
            'stateLabel' => $conversation->automation_state->value === 'human_handoff'
                ? 'AI временно приостановлен'
                : 'AI-помощник активен',
            'pending' => $pending,
            'canReinspectRecentImages' => $canReinspectRecentImages,
            'openEscalation' => $openEscalation === null ? null : [
                'reason' => $openEscalation->reason->value,
                'reasonLabel' => $openEscalation->reasonLabel(),
                'openedAt' => $openEscalation->opened_at->toIso8601String(),
            ],
        ];
        if ($staff) {
            $result['conversation'] = ['id' => $conversation->getKey()];
        }

        return $result;
    }

    private function assertClientOrganization(Client $client, int $organizationId): void
    {
        if ((int) $client->organization_id !== $organizationId) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
    }

    private function roleLabel(ConversationAuthorType $authorType): string
    {
        return match ($authorType) {
            ConversationAuthorType::Client => 'Клиент',
            ConversationAuthorType::Ai => 'AI-помощник',
            ConversationAuthorType::Staff => 'Специалист',
            ConversationAuthorType::System => 'Система',
        };
    }
}
