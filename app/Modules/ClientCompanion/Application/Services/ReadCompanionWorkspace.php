<?php

namespace App\Modules\ClientCompanion\Application\Services;

use App\Models\User;
use App\Modules\ClientCompanion\Domain\Models\CompanionMessageAttachment;
use App\Modules\Conversations\Domain\Enums\ConversationAuthorType;
use App\Modules\Conversations\Domain\Enums\ConversationAutomationState;
use App\Modules\Conversations\Domain\Enums\ConversationType;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Application\ClientSearch;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationAuthorizer;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Application\OrganizationFeatureGate;
use App\Modules\Organizations\Domain\Enums\OrganizationFeature;
use App\Modules\Organizations\Domain\Enums\OrganizationPermission;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\ListClientBookingsForCrm;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Support\RichText\RichTextDocument;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Throwable;

final readonly class ReadCompanionWorkspace
{
    public function __construct(
        private OrganizationContext $context,
        private OrganizationAuthorizer $authorizer,
        private OrganizationFeatureGate $features,
        private ClientSearch $clientSearch,
        private CompanionMessageBodyReader $bodyReader,
    ) {}

    /** @return list<array<string, mixed>> */
    public function dialogs(User $actor, string $search = '', ?int $selectedClientId = null): array
    {
        $organization = $this->authorize($actor);
        $organizationId = (int) $organization->getKey();
        $search = trim($search);

        $clients = $search === ''
            ? collect()
            : $this->clientSearch
                ->query($actor, $search)
                ->select(['id', 'organization_id', 'full_name', 'email', 'phone', 'language', 'timezone'])
                ->orderBy('full_name')
                ->orderBy('id')
                ->get();

        if ($search !== '') {
            $clientIds = $clients->pluck('id')->all();
            $conversations = $clientIds === []
                ? collect()
                : $this->conversationQuery($organizationId)
                    ->whereIn('client_id', $clientIds)
                    ->limit(ClientSearch::MAX_RESULTS)
                    ->get();
            $clientsById = $clients->keyBy(fn (Client $client): int => (int) $client->getKey());
        } else {
            $conversations = $this->conversationQuery($organizationId)->limit(ClientSearch::MAX_RESULTS)->get();
            $clientsById = $conversations
                ->map(fn (Conversation $conversation): ?Client => $conversation->client)
                ->filter()
                ->keyBy(fn (Client $client): int => (int) $client->getKey());
        }

        $selectedClient = $selectedClientId === null
            ? null
            : Client::query()
                ->where('organization_id', $organizationId)
                ->whereKey($selectedClientId)
                ->first();

        if ($selectedClient instanceof Client) {
            $clientsById->put((int) $selectedClient->getKey(), $selectedClient);
        }

        $conversationsByClient = $conversations->keyBy(fn (Conversation $conversation): int => (int) $conversation->client_id);
        if ($selectedClient instanceof Client && ! $conversationsByClient->has($selectedClient->getKey())) {
            $selectedConversation = $this->conversationQuery($organizationId)
                ->where('client_id', $selectedClient->getKey())
                ->first();
            if ($selectedConversation instanceof Conversation) {
                $conversations->push($selectedConversation);
                $conversationsByClient->put($selectedClient->getKey(), $selectedConversation);
            }
        }
        $latestMessageIds = $conversations
            ->map(fn (Conversation $conversation): mixed => $conversation->latestMessage?->getKey())
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $attachmentCounts = $latestMessageIds === []
            ? collect()
            : CompanionMessageAttachment::query()
                ->where('organization_id', $organizationId)
                ->whereIn('conversation_message_id', $latestMessageIds)
                ->selectRaw('conversation_message_id, COUNT(*) as attachment_count')
                ->groupBy('conversation_message_id')
                ->pluck('attachment_count', 'conversation_message_id');

        $result = [];
        foreach ($clientsById as $client) {
            $clientId = (int) $client->getKey();
            $conversation = $conversationsByClient->get($clientId);
            if (! $conversation instanceof Conversation && $selectedClientId !== $clientId && $search === '') {
                continue;
            }

            $result[] = $this->dialog(
                $client,
                $conversation,
                (int) ($attachmentCounts->get($conversation?->latestMessage?->getKey()) ?? 0),
                $selectedClientId === $clientId,
            );
        }

        usort($result, static function (array $left, array $right): int {
            return [$right['lastActivity'] ?? '', $right['clientId']]
                <=> [$left['lastActivity'] ?? '', $left['clientId']];
        });

        return $result;
    }

    /** @return array<string, mixed> */
    public function clientSummary(User $actor, Client $client): array
    {
        $organization = $this->authorize($actor);
        $organizationId = (int) $organization->getKey();
        $this->assertClientOrganization($client, $organizationId);

        $telegramConnected = ClientChannelIdentity::query()
            ->where('organization_id', $organizationId)
            ->where('client_id', $client->getKey())
            ->where('channel', 'telegram')
            ->where('verification_status', ChannelIdentityStatus::Verified)
            ->exists();
        $upcomingBooking = app(ListClientBookingsForCrm::class)
            ->query($actor, $client)
            ->where('starts_at', '>=', now('UTC'))
            ->whereIn('status', BookingStatus::qualifyingFutureValues())
            ->reorder('starts_at')
            ->orderBy('id')
            ->first();

        return [
            'name' => $client->full_name ?: 'Клиент',
            'initials' => $this->initials($client->full_name),
            'phone' => $client->phone,
            'email' => $client->email,
            'language' => $client->language,
            'telegramConnected' => $telegramConnected,
            'upcomingBooking' => $upcomingBooking instanceof Booking
                ? $this->booking($upcomingBooking)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function dialog(Client $client, ?Conversation $conversation, int $attachmentCount, bool $selected): array
    {
        $latestMessage = $conversation instanceof Conversation ? $conversation->latestMessage : null;
        $lastActivity = $latestMessage?->occurred_at;
        if ($conversation instanceof Conversation) {
            $lastActivity = $conversation->last_message_at ?? $lastActivity ?? $conversation->started_at;
        }
        $telegramBinding = $conversation instanceof Conversation
            ? $conversation->bindings->first(static fn (mixed $binding): bool => $binding->channel === 'telegram')
            : null;
        $channel = $telegramBinding?->channel;
        if ($channel === null && $latestMessage instanceof ConversationMessage) {
            $channel = $latestMessage->channel;
        }

        return [
            'clientId' => $client->getKey(),
            'name' => $client->full_name ?: 'Клиент',
            'initials' => $this->initials($client->full_name),
            'preview' => $this->preview($latestMessage, $attachmentCount),
            'lastActivity' => $lastActivity?->toIso8601String(),
            'lastActivityLabel' => $this->timeLabel($lastActivity),
            'channel' => $channel,
            'channelLabel' => $this->channelLabel($channel),
            'stateLabel' => $this->stateLabel($conversation, $latestMessage?->author_type),
            'hasConversation' => $conversation instanceof Conversation,
            'selected' => $selected,
        ];
    }

    private function preview(mixed $message, int $attachmentCount): string
    {
        if (! $message instanceof ConversationMessage) {
            return '';
        }

        try {
            $content = RichTextDocument::plainText($this->bodyReader->read(
                (int) $message->organization_id,
                $message,
            ));
        } catch (Throwable) {
            $content = 'Сообщение недоступно';
        }

        if ($content === '' && $attachmentCount > 0) {
            return 'Вложение';
        }

        return Str::limit($content, 90);
    }

    private function stateLabel(?Conversation $conversation, ?ConversationAuthorType $latestAuthor): string
    {
        if (! $conversation instanceof Conversation) {
            return 'Диалог не начат';
        }

        if ($conversation->automation_state !== ConversationAutomationState::HumanHandoff) {
            return 'AI отвечает';
        }

        return $latestAuthor === ConversationAuthorType::Staff
            ? 'Диалог ведёт специалист'
            : 'Нужен специалист';
    }

    private function channelLabel(?string $channel): ?string
    {
        return match (strtolower((string) $channel)) {
            'telegram' => 'Telegram',
            'portal' => 'Портал',
            '' => null,
            default => 'Канал',
        };
    }

    private function timeLabel(?CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        $local = $date->copy()->setTimezone($this->context->defaultTimezone());
        $today = now($this->context->defaultTimezone());

        return $local->isSameDay($today)
            ? $local->format('H:i')
            : $local->format('d.m');
    }

    private function initials(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($parts) || $parts === []) {
            return 'К';
        }

        $initials = mb_substr((string) $parts[0], 0, 1);
        if (count($parts) > 1) {
            $initials .= mb_substr((string) $parts[1], 0, 1);
        }

        return mb_strtoupper($initials);
    }

    /** @return array<string, mixed> */
    private function booking(Booking $booking): array
    {
        $localStart = $booking->startsAtUtc()->setTimezone($this->context->defaultTimezone());

        return [
            'id' => $booking->getKey(),
            'date' => $localStart->format('d.m.Y'),
            'time' => $localStart->format('H:i'),
            'service' => $booking->service->name,
            'status' => $this->bookingStatusLabel($booking->status),
        ];
    }

    private function bookingStatusLabel(BookingStatus $status): string
    {
        return match ($status) {
            BookingStatus::Requested => 'Ожидает подтверждения',
            BookingStatus::PendingReview => 'На рассмотрении',
            BookingStatus::Confirmed => 'Подтверждена',
            default => 'Активна',
        };
    }

    private function authorize(User $actor): Organization
    {
        $organization = $this->context->organization();
        $this->features->authorize($organization, OrganizationFeature::ClientRecords);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewClients);
        $this->authorizer->authorize($actor, $organization, OrganizationPermission::ViewCompanionHistory);

        return $organization;
    }

    private function assertClientOrganization(Client $client, int $organizationId): void
    {
        if ((int) $client->organization_id !== $organizationId) {
            throw new AuthorizationException('The client is outside the current organization.');
        }
    }

    /** @return Builder<Conversation> */
    private function conversationQuery(int $organizationId): Builder
    {
        return Conversation::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_type', ConversationType::ClientCompanion)
            ->with([
                'client:id,organization_id,full_name,email,phone,language,timezone',
                'latestMessage.authorUser:id,name',
                'bindings:id,organization_id,conversation_id,client_id,channel,external_key',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('started_at')
            ->orderByDesc('id');
    }
}
