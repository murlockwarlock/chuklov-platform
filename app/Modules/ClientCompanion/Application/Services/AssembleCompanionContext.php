<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\ClientCompanion\Domain\Models\CompanionMessageAttachment;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurnMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Organizations\Domain\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use LogicException;

final class AssembleCompanionContext
{
    public function __construct(
        private readonly CompanionMessageBodyReader $bodyReader,
        private readonly GetCompanionContextSettings $settings,
    ) {}

    /** @return array{current_message: string, conversation_history: string, attachment_ids: list<int>, required_modalities: list<AiModelModality>} */
    public function handle(int $organizationId, Conversation $conversation, CompanionTurn $turn): array
    {
        $currentEntries = CompanionTurnMessage::query()
            ->where('organization_id', $organizationId)
            ->where('turn_id', $turn->getKey())
            ->with('conversationMessage.companionAttachments')
            ->orderBy('sequence')
            ->limit(max(1, (int) config('ai.companion.maximum_burst_messages', 4)))
            ->get();
        $currentMessages = $currentEntries
            ->map(fn (CompanionTurnMessage $entry): ?ConversationMessage => $entry->conversationMessage)
            ->filter()
            ->sortBy(function (ConversationMessage $message): array {
                $attachment = $message->relationLoaded('companionAttachments')
                    ? $message->companionAttachments->sortBy('source_ordinal')->first()
                    : null;

                $occurredAt = $message->occurred_at;

                return [
                    $attachment === null ? PHP_INT_MAX : $attachment->source_ordinal,
                    $occurredAt instanceof CarbonInterface ? $occurredAt->getTimestamp() : 0,
                    (int) $message->getKey(),
                ];
            })
            ->values();
        $currentMessage = $this->semanticMessages($organizationId, $currentMessages);
        if ($currentMessage === '') {
            $currentMessage = '[Сообщение клиента]';
        }

        $organization = $conversation->organization;
        if (! $organization instanceof Organization) {
            throw new LogicException('The Companion conversation organization is unavailable.');
        }
        $settings = $this->settings->handle($organization);
        $firstTurns = $this->turns($organizationId, $conversation, $turn, $settings['first_exchanges'], false);
        $recentTurns = $this->turns($organizationId, $conversation, $turn, $settings['recent_exchanges'], true);
        $selected = $firstTurns->merge($recentTurns)->unique('id')->sortBy('sequence')->values();
        $maxCharacters = min(
            24000,
            max(2000, (int) config('ai.companion.context_max_input_characters', config('ai.companion.context_max_characters', 12000))),
        );
        $history = [];
        $historyLength = 0;
        foreach ($selected as $historyTurn) {
            $group = $this->turnGroup($organizationId, $historyTurn);
            if ($group === '' || $historyLength + mb_strlen($group) + 2 > $maxCharacters - mb_strlen($currentMessage)) {
                continue;
            }
            $history[] = $group;
            $historyLength += mb_strlen($group) + 2;
        }

        $attachmentIds = $this->boundedAttachmentIds($organizationId, $conversation, $turn, $recentTurns);

        $requiredModalities = [];
        if ($attachmentIds !== []) {
            $requiredModalities[] = AiModelModality::ImageInput;
        }

        return [
            'current_message' => $currentMessage,
            'conversation_history' => implode("\n\n", $history),
            'attachment_ids' => $attachmentIds,
            'required_modalities' => $requiredModalities,
        ];
    }

    /** @return Collection<int, CompanionTurn> */
    private function turns(int $organizationId, Conversation $conversation, CompanionTurn $current, int $limit, bool $recent): Collection
    {
        if ($limit === 0) {
            return collect();
        }

        return CompanionTurn::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversation->getKey())
            ->where('context_epoch', $current->context_epoch)
            ->where('sequence', '<', $current->sequence)
            ->whereNotNull('outbound_message_id')
            ->whereIn('status', ['completed', 'failed', 'escalated'])
            ->with(['messages.conversationMessage.companionAttachments', 'outboundMessage', 'attachments.medicalAttachment'])
            ->when($recent, fn ($query) => $query->orderByDesc('sequence'), fn ($query) => $query->orderBy('sequence'))
            ->limit(min(20, $limit))
            ->get()
            ->when($recent, fn ($items) => $items->sortBy('sequence')->values());
    }

    private function turnGroup(int $organizationId, CompanionTurn $turn): string
    {
        $inbound = $turn->messages
            ->sortBy(fn (CompanionTurnMessage $entry): int => $entry->sequence)
            ->map(fn (CompanionTurnMessage $entry): ?ConversationMessage => $entry->conversationMessage)
            ->filter();
        $parts = [];
        $clientText = $this->semanticMessages($organizationId, $inbound);
        if ($clientText === '') {
            return '';
        }

        $parts[] = '[Client] '.$clientText;
        $outbound = $turn->outboundMessage;
        if ($outbound instanceof ConversationMessage) {
            $text = trim($this->bodyReader->read($organizationId, $outbound));
            if ($text !== '') {
                $parts[] = match ($outbound->author_type) {
                    ConversationAuthorType::Staff => '[Specialist] '.$text,
                    default => '[AI] '.$text,
                };
            }
        }

        return implode("\n", $parts);
    }

    /** @param iterable<ConversationMessage> $messages */
    private function semanticMessages(int $organizationId, iterable $messages): string
    {
        $parts = [];
        $captionHashes = [];
        foreach ($messages as $message) {
            $text = trim($this->bodyReader->read($organizationId, $message));
            $messageAttachments = $message->relationLoaded('companionAttachments')
                ? $message->companionAttachments->sortBy('source_ordinal')->values()
                : CompanionMessageAttachment::query()
                    ->where('organization_id', $organizationId)
                    ->where('conversation_message_id', $message->getKey())
                    ->orderBy('source_ordinal')
                    ->orderBy('item_index')
                    ->get();
            if ($messageAttachments->isNotEmpty()) {
                if ($text === '[Изображение]' || $text === '') {
                    $text = '';
                } elseif (isset($captionHashes[hash('sha256', $text)])) {
                    $text = '';
                } else {
                    $captionHashes[hash('sha256', $text)] = true;
                }
                $text .= ($text === '' ? '' : "\n").'[Изображение: '.$messageAttachments->count().']';
            }
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  Collection<int, CompanionTurn>  $recentTurns
     * @return list<int>
     */
    private function boundedAttachmentIds(int $organizationId, Conversation $conversation, CompanionTurn $current, Collection $recentTurns): array
    {
        $turns = collect([$current]);
        $recentImageTurns = $recentTurns->filter(fn (CompanionTurn $turn): bool => $turn->input_modality === 'image')
            ->sortByDesc('sequence')
            ->take(max(0, (int) config('ai.companion.recent_image_turns', 1)));
        $turns = $turns->merge($recentImageTurns);
        $ids = [];
        $totalBytes = 0;
        $maxImages = max(1, (int) config('ai.companion.maximum_images_per_turn', 10));
        $maxBytes = max(1, (int) config('ai.companion.maximum_image_total_bytes', 20_971_520));
        foreach ($turns as $candidate) {
            $attachments = $candidate->relationLoaded('attachments')
                ? $candidate->attachments
                : CompanionMessageAttachment::query()
                    ->where('organization_id', $organizationId)
                    ->where('conversation_id', $conversation->getKey())
                    ->where('turn_id', $candidate->getKey())
                    ->with('medicalAttachment')
                    ->orderBy('source_ordinal')
                    ->orderBy('item_index')
                    ->limit($maxImages)
                    ->get();
            foreach ($attachments as $attachment) {
                $medical = $attachment->medicalAttachment;
                $bytes = $medical === null ? 0 : $medical->size_bytes;
                if ($medical === null || count($ids) >= $maxImages || $totalBytes + $bytes > $maxBytes) {
                    continue;
                }
                $ids[] = (int) $medical->getKey();
                $totalBytes += $bytes;
            }
        }

        $uniqueIds = [];
        foreach ($ids as $id) {
            if (! in_array($id, $uniqueIds, true)) {
                $uniqueIds[] = $id;
            }
        }

        return $uniqueIds;
    }
}
