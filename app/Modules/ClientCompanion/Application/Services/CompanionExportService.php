<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Models\User;
use App\Modules\AI\Domain\Models\AiRun;
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
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use RuntimeException;

final class CompanionExportService
{
    private const MAX_EXPORT_MESSAGES = 2000;

    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly CompanionMessageBodyReader $bodyReader,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function history(User $actor, Client $client, string $format, string $identityMode): string
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ExportCompanionHistory);
        $this->assertClient($client, (int) $organization->getKey());
        if (! in_array($format, ['txt', 'json'], true) || ! in_array($identityMode, ['identified', 'pseudonymized'], true)) {
            throw new RuntimeException('The Companion export format is not available.');
        }

        $data = $this->historyData($client, $identityMode === 'identified');
        $conversationId = Conversation::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('conversation_type', ConversationType::ClientCompanion)
            ->value('id');
        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'companion.export.created',
            targetType: Conversation::class,
            targetId: $conversationId === null ? null : (string) $conversationId,
            metadata: ['format' => $format, 'identified' => $identityMode === 'identified', 'metadata_only' => false],
        );

        return $format === 'txt' ? $this->toTxt($data) : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function metadata(User $actor, Client $client): string
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ExportCompanionMetadata);
        $this->assertClient($client, (int) $organization->getKey());
        $conversation = Conversation::query()
            ->where('organization_id', $organization->getKey())
            ->where('client_id', $client->getKey())
            ->where('conversation_type', ConversationType::ClientCompanion)
            ->first();
        $conversationId = $conversation?->getKey() ?? 0;
        $turns = CompanionTurn::query()
            ->where('organization_id', $organization->getKey())
            ->where('conversation_id', $conversationId)
            ->orderBy('sequence')
            ->limit($this->exportLimit())
            ->get();
        $turnIds = $turns->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $escalations = CompanionEscalation::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('turn_id', $turnIds)
            ->get()
            ->keyBy('turn_id');
        $feedback = CompanionFeedback::query()
            ->where('organization_id', $organization->getKey())
            ->where('conversation_id', $conversationId)
            ->get();
        $deliveryStatuses = CompanionDelivery::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('turn_id', $turnIds)
            ->selectRaw('turn_id, status, COUNT(*) as chunk_count')
            ->groupBy('turn_id', 'status')
            ->get()
            ->groupBy('turn_id');
        $canSeeRuns = $this->authorizer->allows($actor, $organization, OrganizationPermission::ViewAiRuns);
        $runIds = $canSeeRuns ? $turns->pluck('ai_run_id')->filter()->unique()->values()->all() : [];
        $runs = AiRun::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('id', $runIds)
            ->get()
            ->keyBy('id');

        $turnItems = [];
        foreach ($turns as $turn) {
            $run = $runs->get($turn->ai_run_id);
            $deliveryItems = [];
            $deliveryGroup = $deliveryStatuses->get($turn->getKey());
            if ($deliveryGroup instanceof Collection) {
                foreach ($deliveryGroup as $item) {
                    $deliveryItems[] = [
                        'status' => $item->status->value,
                        'chunk_count' => (int) $item->chunk_count,
                    ];
                }
            }

            $turnItems[] = [
                'sequence' => $turn->sequence,
                'status' => $turn->status->value,
                'origin_transport' => $turn->origin_channel,
                'accepted_at' => $turn->accepted_at?->toIso8601String(),
                'processing_started_at' => $turn->processing_started_at?->toIso8601String(),
                'completed_at' => $turn->completed_at?->toIso8601String(),
                'failed_at' => $turn->failed_at?->toIso8601String(),
                'escalated_at' => $turn->escalated_at?->toIso8601String(),
                'failure_code' => $turn->failure_code,
                'handoff_reason' => $escalations->get($turn->getKey())?->reason?->value,
                'delivery' => $deliveryItems,
                'ai_observability' => $canSeeRuns && $run instanceof AiRun ? [
                    'ai_run_id' => $run->getKey(),
                    'status' => $run->status->value,
                    'origin' => $run->origin->value,
                    'prompt_version_id' => $run->prompt_version_id,
                    'model_release_id' => $run->model_release_id,
                    'provider' => $run->actual_provider,
                    'model' => $run->actual_model,
                    'latency_ms' => $run->latency_ms,
                    'cost_minor_units' => $run->settled_estimated_cost_minor_units,
                    'currency' => $run->cost_currency,
                ] : null,
            ];
        }

        $feedbackItems = [];
        foreach ($feedback as $item) {
            $feedbackItems[] = [
                'message_role' => 'ai',
                'value' => $item->value->value,
                'reason' => $item->reason,
                'created_at' => $item->created_at?->toIso8601String(),
            ];
        }

        $result = [
            'schema_version' => 'client_companion_metadata_v1',
            'identity' => ['label' => 'client_'.$client->getKey(), 'client_id' => $canSeeRuns ? $client->getKey() : null],
            'conversation' => $conversation === null ? null : [
                'state' => $conversation->automation_state->value,
                'context_epoch' => $conversation->context_epoch,
            ],
            'turns' => $turnItems,
            'feedback' => $feedbackItems,
        ];
        $this->audit->handle(
            organization: $organization,
            actor: $actor,
            action: 'companion.export.created',
            targetType: Conversation::class,
            targetId: $conversation?->getKey() === null ? null : (string) $conversation->getKey(),
            metadata: ['format' => 'json', 'identified' => false, 'metadata_only' => true],
        );

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function historyData(Client $client, bool $identified): array
    {
        $organizationId = $this->context->id();
        $conversation = Conversation::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('conversation_type', ConversationType::ClientCompanion)
            ->first();
        if ($conversation === null) {
            return [
                'schema_version' => 'client_companion_history_v1',
                'identity' => $this->identity($client, $identified),
                'conversation' => null,
                'messages' => [],
                'events' => [],
            ];
        }

        $limit = $this->exportLimit();
        $messages = ConversationMessage::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversation->getKey())
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();
        if ($messages->count() > $limit) {
            throw new RuntimeException('The Companion history is too large for a bounded download.');
        }
        $messageIds = $messages->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
        $turns = CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversation->getKey())
            ->where(function ($query) use ($messageIds): void {
                $query->whereIn('inbound_message_id', $messageIds)->orWhereIn('outbound_message_id', $messageIds);
            })
            ->get();
        $turnByMessage = [];
        foreach ($turns as $turn) {
            $turnByMessage[(int) $turn->inbound_message_id] = $turn;
            if ($turn->outbound_message_id !== null) {
                $turnByMessage[(int) $turn->outbound_message_id] = $turn;
            }
        }
        $turnIds = $turns->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $feedback = CompanionFeedback::query()
            ->where('organization_id', $organizationId)
            ->whereIn('message_id', $messageIds)
            ->get()
            ->keyBy('message_id');
        $attachmentCounts = $messageIds === [] ? collect() : CompanionMessageAttachment::query()
            ->where('organization_id', $organizationId)
            ->whereIn('conversation_message_id', $messageIds)
            ->selectRaw('conversation_message_id, COUNT(*) as attachment_count')
            ->groupBy('conversation_message_id')
            ->pluck('attachment_count', 'conversation_message_id');
        $events = CompanionEscalation::query()
            ->where('organization_id', $organizationId)
            ->whereIn('turn_id', $turnIds)
            ->orderBy('opened_at')
            ->get();

        $messageItems = [];
        foreach ($messages->chunk(200) as $messageBatch) {
            foreach ($messageBatch as $message) {
                $item = [
                    'role' => match ($message->author_type) {
                        ConversationAuthorType::Client => 'client',
                        ConversationAuthorType::Ai => 'ai',
                        ConversationAuthorType::Staff => 'staff',
                        ConversationAuthorType::System => 'system',
                    },
                    'content' => $this->bodyReader->read($organizationId, $message),
                    'timestamp' => $message->occurred_at?->toIso8601String() ?? $message->created_at?->toIso8601String() ?? now()->toIso8601String(),
                    'transport' => $message->channel === 'telegram' ? 'telegram' : 'portal',
                    'attachment_count' => (int) ($attachmentCounts->get($message->getKey()) ?? 0),
                    'feedback' => null,
                ];
                $messageFeedback = $feedback->get($message->getKey());
                if ($messageFeedback instanceof CompanionFeedback) {
                    $item['feedback'] = ['value' => $messageFeedback->value->value, 'reason' => $messageFeedback->reason];
                }
                $messageItems[] = $item;
            }
        }

        return [
            'schema_version' => 'client_companion_history_v1',
            'identity' => $this->identity($client, $identified),
            'conversation' => ['logical' => $identified ? 'client_'.$client->getKey() : 'client_1'],
            'messages' => $messageItems,
            'events' => $events->map(fn (CompanionEscalation $event): array => [
                'type' => 'handoff',
                'reason' => $event->reason->value,
                'status' => $event->status->value,
                'timestamp' => $event->opened_at->toIso8601String(),
                'resolved_at' => $event->resolved_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function identity(Client $client, bool $identified): array
    {
        if (! $identified) {
            return [
                'label' => 'client_1',
                'note' => 'Прямые идентификаторы удалены; текст разговора может содержать раскрытые клиентом сведения.',
            ];
        }

        return array_filter([
            'label' => 'client_'.$client->getKey(),
            'client_id' => $client->getKey(),
            'name' => $client->full_name,
            'phone' => $client->phone,
            'email' => $client->email,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string, mixed> $data */
    private function toTxt(array $data): string
    {
        $lines = ['CHUKLOV — AI-компаньон', 'Схема экспорта: '.$data['schema_version'], ''];
        $identity = (array) ($data['identity'] ?? []);
        if (isset($identity['label'])) {
            $lines[] = 'Идентификатор экспорта: '.$identity['label'];
        }
        foreach (['name' => 'Имя', 'phone' => 'Телефон', 'email' => 'Email'] as $key => $label) {
            if (isset($identity[$key]) && $identity[$key] !== '') {
                $lines[] = $label.': '.$identity[$key];
            }
        }
        if (isset($identity['note'])) {
            $lines[] = (string) $identity['note'];
        }
        $lines[] = '';
        foreach ($data['messages'] as $message) {
            $role = match ($message['role']) {
                'client' => 'Client',
                'ai' => 'AI',
                'staff' => 'Specialist',
                default => 'System',
            };
            $transport = $message['transport'] === 'telegram' ? 'Telegram' : 'Портал';
            $lines[] = '['.$message['timestamp'].'] ['.$transport.'] '.$role.':';
            if (($message['attachment_count'] ?? 0) > 0) {
                $lines[] = '['.($message['attachment_count'] === 1 ? 'Изображение' : $message['attachment_count'].' изображения').']';
            }
            $lines[] = (string) $message['content'];
            $lines[] = '';
        }
        foreach ($data['events'] as $event) {
            $lines[] = '['.$event['timestamp'].'] Handoff: '.$event['reason'];
        }

        return implode("\n", $lines);
    }

    private function assertClient(Client $client, int $organizationId): void
    {
        if ((int) $client->organization_id !== $organizationId) {
            throw new AuthorizationException('The client is outside the organization.');
        }
    }

    private function exportLimit(): int
    {
        return min(self::MAX_EXPORT_MESSAGES, max(1, (int) config('ai.companion.maximum_export_messages', self::MAX_EXPORT_MESSAGES)));
    }
}
