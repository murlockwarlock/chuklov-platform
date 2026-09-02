<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\Channels\Infrastructure\Telegram\TelegramBotIdentityVerifier;
use App\Modules\Identity\Application\RefreshTelegramClientIdentity;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class HandleTelegramCompanionText
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AcceptCompanionMessage $accept,
        private readonly TelegramBotIdentityVerifier $identityVerifier,
        private readonly RefreshTelegramClientIdentity $refreshIdentity,
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

        try {
            $client = $this->refreshIdentity->handle($organization, $this->identityVerifier->handle($bot));
        } catch (UnauthorizedHttpException) {
            $this->sendLinkPath($bot);

            return;
        }
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
