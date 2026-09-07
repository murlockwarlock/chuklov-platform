<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\Channels\Infrastructure\Telegram\TelegramBotIdentityVerifier;
use App\Modules\ClientCompanion\Domain\Models\CompanionTurn;
use App\Modules\Identity\Application\RefreshTelegramClientIdentity;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Http\UploadedFile;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Media\Document;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class HandleTelegramCompanionDocument
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AcceptCompanionMessage $accept,
        private readonly UploadCompanionDocument $upload,
        private readonly TelegramBotIdentityVerifier $identityVerifier,
        private readonly RefreshTelegramClientIdentity $refreshIdentity,
    ) {}

    public function handle(Nutgram $bot): void
    {
        $chat = $bot->chat();
        $message = $bot->message();
        $document = $message?->document;
        if ($chat === null || $chat->type !== ChatType::PRIVATE || ! $document instanceof Document) {
            return;
        }

        $caption = trim((string) ($message->caption ?? ''));
        if ($caption !== '' && str_starts_with($caption, '/')) {
            return;
        }

        $organization = $this->organization();
        $user = $bot->user();
        if ($organization === null || $user === null) {
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
        $messageId = $message->message_id;
        $originExternalId = $chatId.':'.$messageId;
        $existing = CompanionTurn::query()
            ->where('organization_id', $organization->getKey())
            ->where('origin_channel', 'telegram')
            ->where('origin_external_id', $originExternalId)
            ->first();
        if ($existing instanceof CompanionTurn) {
            return;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'cc_telegram_');
        if ($temporaryPath === false) {
            $this->acceptUnavailable($client, $chatId, $originExternalId, $caption, $messageId, $bot);

            return;
        }

        try {
            $maxBytes = (int) config('medical.attachment_max_bytes', 20_971_520);
            if ($document->file_size !== null && $document->file_size > $maxBytes) {
                $this->acceptUnavailable($client, $chatId, $originExternalId, $caption, $messageId, $bot);

                return;
            }
            if ($document->download($temporaryPath) !== true) {
                $this->acceptUnavailable($client, $chatId, $originExternalId, $caption, $messageId, $bot);

                return;
            }

            $filename = basename((string) ($document->file_name ?: 'telegram-'.$messageId.'.pdf'));
            $file = new UploadedFile($temporaryPath, $filename, 'application/pdf', UPLOAD_ERR_OK, true);
            $attachment = $this->upload->handle($client, $file);

            $this->accept->handle(
                client: $client,
                channel: 'telegram',
                body: $caption,
                idempotencyKey: null,
                originExternalId: $originExternalId,
                transportChatId: $chatId,
                locale: (string) ($user->language_code ?? 'en'),
                attachmentIds: [(int) $attachment->getKey()],
                sourceOrdinal: $messageId,
                payloadHash: hash('sha256', json_encode([
                    'checksum' => $attachment->sha256_checksum,
                    'document_file_unique_id' => $document->file_unique_id,
                ], JSON_THROW_ON_ERROR)),
            );
        } catch (\Throwable) {
            $this->acceptUnavailable($client, $chatId, $originExternalId, $caption, $messageId, $bot);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function acceptUnavailable(
        Client $client,
        string $chatId,
        string $originExternalId,
        string $caption,
        int $messageId,
        Nutgram $bot,
    ): void {
        try {
            $this->accept->handle(
                client: $client,
                channel: 'telegram',
                body: $caption !== '' ? $caption : '[Документ не удалось получить]',
                idempotencyKey: null,
                originExternalId: $originExternalId,
                transportChatId: $chatId,
                locale: (string) ($bot->user()->language_code ?? 'en'),
                sourceOrdinal: $messageId,
                payloadHash: hash('sha256', $originExternalId.'|document-unavailable'),
                inputFailureCode: 'document_unavailable',
            );
        } catch (\Throwable) {
            $user = $bot->user();
            $language = $user instanceof TelegramUser ? ($user->language_code ?? '') : '';
            $bot->sendMessage(str_starts_with(strtolower((string) $language), 'ru')
                ? 'Не удалось принять PDF-документ. Отправьте его ещё раз или напишите специалисту.'
                : 'The PDF document could not be accepted. Please send it again or contact a specialist.');
        }
    }

    private function organization(): ?Organization
    {
        $id = config('tenancy.default_organization_id');

        return is_numeric($id) ? Organization::query()->find((int) $id) : null;
    }

    private function sendLinkPath(Nutgram $bot): void
    {
        $user = $bot->user();
        $language = str_starts_with(strtolower((string) ($user instanceof TelegramUser ? ($user->language_code ?? '') : '')), 'ru') ? 'ru' : 'en';
        $bot->sendMessage($language === 'ru'
            ? 'Чтобы начать общение, сначала подключите Telegram к своему аккаунту в портале.'
            : 'To start a conversation, first connect Telegram to your account in the Portal.');
    }
}
