<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Models\User;
use App\Modules\Attachments\Domain\Enums\AttachmentType;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Channels\Infrastructure\Telegram\TelegramCompanionFormatter;
use App\Modules\ClientCompanion\Domain\Enums\CompanionDeliveryStatus;
use App\Modules\ClientCompanion\Domain\Models\CompanionDelivery;
use App\Modules\ClientCompanion\Domain\Models\CompanionMessageAttachment;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\ClientCompanion\Infrastructure\Jobs\DeliverCompanionMessage;
use App\Modules\Conversations\Application\RecordCompanionMessage;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationDirection;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationBinding;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Support\RichText\RichTextDocument;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ReplyToCompanion
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly OrganizationAuthorizer $authorizer,
        private readonly RecordCompanionMessage $recordMessage,
        private readonly TelegramCompanionFormatter $formatter,
        private readonly GetOrCreateClientCompanionConversation $conversationResolver,
    ) {}

    /** @param list<int> $attachmentIds */
    public function handle(User $actor, Client $client, string $body, array $attachmentIds = []): string
    {
        $organization = $this->context->organization();
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ManageCompanionHandoff);
        if ((int) $client->organization_id !== (int) $organization->getKey()) {
            throw new AuthorizationException('The Companion conversation is outside the organization.');
        }
        $rateKey = 'companion-staff-reply:'.$organization->getKey().':'.$client->getKey();
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            throw new TooManyRequestsHttpException(null, 'Companion replies are temporarily rate limited.');
        }
        RateLimiter::hit($rateKey, 60);

        $body = RichTextDocument::canonicalHtml($body);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Введите сообщение.']);
        }
        $recordMessage = $this->recordMessage;
        $formatter = $this->formatter;
        $attachmentIds = array_values(array_unique(array_map('intval', $attachmentIds)));
        if ($attachmentIds !== [] && RichTextDocument::exceedsTelegramLimit($body, caption: true)) {
            throw ValidationException::withMessages([
                'body' => 'С вложением подпись Telegram не может быть длиннее 1024 символов.',
            ]);
        }

        $conversationResolver = $this->conversationResolver;
        $result = DB::transaction(function () use ($organization, $actor, $client, $body, $recordMessage, $formatter, $attachmentIds, $conversationResolver): array {
            $telegramIdentity = ClientChannelIdentity::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('channel', 'telegram')
                ->where('verification_status', ChannelIdentityStatus::Verified)
                ->orderBy('id')
                ->first();
            $conversation = Conversation::query()
                ->where('organization_id', $organization->getKey())
                ->where('client_id', $client->getKey())
                ->where('conversation_type', ConversationType::ClientCompanion)
                ->lockForUpdate()
                ->first();
            if (! $conversation instanceof Conversation) {
                $conversation = $conversationResolver->handle(
                    $client,
                    $telegramIdentity instanceof ClientChannelIdentity ? 'telegram' : 'portal',
                    $telegramIdentity instanceof ClientChannelIdentity
                        ? (string) $telegramIdentity->external_id
                        : 'client:'.$client->getKey(),
                );
            } elseif ($telegramIdentity instanceof ClientChannelIdentity) {
                $conversation = $conversationResolver->handle(
                    $client,
                    'telegram',
                    (string) $telegramIdentity->external_id,
                );
            }
            $turn = CompanionTurn::query()
                ->where('organization_id', $organization->getKey())
                ->where('conversation_id', $conversation->getKey())
                ->latest('sequence')
                ->first();
            $telegramBinding = ConversationBinding::query()
                ->where('organization_id', $organization->getKey())
                ->where('conversation_id', $conversation->getKey())
                ->where('client_id', $client->getKey())
                ->where('channel', 'telegram')
                ->orderBy('id')
                ->first();
            $telegramRecipient = $turn?->origin_channel === 'telegram' && filled($turn->transport_chat_id)
                ? (string) $turn->transport_chat_id
                : ($telegramIdentity instanceof ClientChannelIdentity
                    ? $telegramIdentity->external_id
                    : $telegramBinding?->external_key);
            $channel = $telegramRecipient !== null
                ? 'telegram'
                : ($turn instanceof CompanionTurn && $turn->origin_channel === 'telegram'
                    ? 'portal'
                    : ($turn instanceof CompanionTurn ? $turn->origin_channel ?? 'portal' : 'portal'));
            $message = $recordMessage->handle(
                organizationId: $organization->getKey(),
                client: $client,
                conversation: $conversation,
                channel: $channel,
                direction: ConversationDirection::Outbound,
                authorType: ConversationAuthorType::Staff,
                body: $body,
                authorUserId: $actor->getKey(),
                contextEpoch: $conversation->context_epoch,
                metadata: ['message_type' => 'staff_reply', 'locale' => $client->language ?? 'en', 'transport' => $channel],
            );

            if ($attachmentIds !== []) {
                if ($turn === null || count($attachmentIds) > 1) {
                    throw ValidationException::withMessages(['attachments' => 'Выберите не более одного файла для сообщения.']);
                }

                $attachments = MedicalAttachment::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('client_id', $client->getKey())
                    ->whereIn('attachment_type', [AttachmentType::CompanionImage, AttachmentType::CompanionDocument])
                    ->whereIn('id', $attachmentIds)
                    ->get();
                if ($attachments->count() !== count($attachmentIds)) {
                    throw ValidationException::withMessages(['attachments' => 'Файл нельзя отправить клиенту.']);
                }

                foreach ($attachments as $index => $attachment) {
                    CompanionMessageAttachment::query()->create([
                        'organization_id' => $organization->getKey(),
                        'client_id' => $client->getKey(),
                        'conversation_id' => $conversation->getKey(),
                        'turn_id' => $turn->getKey(),
                        'conversation_message_id' => $message->getKey(),
                        'medical_attachment_id' => $attachment->getKey(),
                        'source_ordinal' => 1,
                        'item_index' => $index + 1,
                    ]);
                }
            }

            if ($channel !== 'telegram' || $telegramRecipient === null) {
                return ['deliveryIds' => [], 'channel' => $channel];
            }

            $chunks = $formatter->richTextChunks($body);
            $ids = [];
            foreach ($chunks as $index => $chunk) {
                $delivery = CompanionDelivery::query()->create([
                    'organization_id' => $organization->getKey(),
                    'turn_id' => null,
                    'conversation_message_id' => $message->getKey(),
                    'channel' => 'telegram',
                    'recipient_external_id' => $telegramRecipient,
                    'chunk_index' => $index,
                    'chunk_count' => count($chunks),
                    'status' => CompanionDeliveryStatus::Pending,
                    'attempt_count' => 0,
                ]);
                $ids[] = (int) $delivery->getKey();
            }

            return ['deliveryIds' => $ids, 'channel' => $channel];
        });

        $deliveryIds = $result['deliveryIds'];
        $firstDeliveryId = $deliveryIds[0] ?? null;
        if ($firstDeliveryId !== null) {
            DeliverCompanionMessage::dispatch(
                $organization->getKey(),
                $firstDeliveryId,
            )->afterCommit();
        }

        return (string) $result['channel'];
    }
}
