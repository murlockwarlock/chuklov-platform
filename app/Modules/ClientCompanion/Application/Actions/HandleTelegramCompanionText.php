<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;

final class HandleTelegramCompanionText
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AcceptCompanionMessage $accept,
    ) {}

    public function handle(Nutgram $bot): void
    {
        $chat = $bot->chat();
        $message = $bot->message();
        $text = trim((string) ($message->text ?? ''));
        if ($chat === null || $chat->type !== ChatType::PRIVATE || $text === '') {
            return;
        }

        $organization = $this->organization();
        if ($organization === null || $bot->user() === null) {
            $this->sendLinkPath($bot);

            return;
        }
        $this->context->set($organization);

        $externalId = (string) $bot->user()->id;
        $identity = ClientChannelIdentity::query()
            ->where('organization_id', $organization->getKey())
            ->where('channel', 'telegram')
            ->where('external_id', $externalId)
            ->where('verification_status', ChannelIdentityStatus::Verified)
            ->first();
        $client = $identity?->client;
        if (! $client instanceof Client || (int) $client->organization_id !== (int) $organization->getKey()) {
            $this->sendLinkPath($bot);

            return;
        }

        $chatId = (string) $chat->id;
        $messageId = $message?->message_id;
        $originExternalId = $messageId === null ? null : $chatId.':'.$messageId;
        $this->accept->handle(
            client: $client,
            channel: 'telegram',
            body: $text,
            idempotencyKey: null,
            originExternalId: $originExternalId,
            transportChatId: $chatId,
            locale: (string) ($bot->user()->language_code ?? 'en'),
        );
    }

    private function organization(): ?Organization
    {
        $organizationId = config('tenancy.default_organization_id');
        if (! is_int($organizationId) && ! (is_string($organizationId) && ctype_digit($organizationId))) {
            return null;
        }

        return Organization::query()->find((int) $organizationId);
    }

    private function sendLinkPath(Nutgram $bot): void
    {
        $language = str_starts_with(strtolower((string) $bot->user()?->language_code), 'ru') ? 'ru' : 'en';
        $message = $language === 'ru'
            ? 'Чтобы начать общение, сначала подключите Telegram к своему аккаунту в портале.'
            : 'To start a conversation, first connect Telegram to your account in the Portal.';
        $bot->sendMessage($message);
    }
}
