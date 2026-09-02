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
use SergiX44\Nutgram\Telegram\Types\Media\PhotoSize;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class HandleTelegramCompanionPhoto
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AcceptCompanionMessage $accept,
        private readonly UploadCompanionImages $upload,
        private readonly TelegramBotIdentityVerifier $identityVerifier,
        private readonly RefreshTelegramClientIdentity $refreshIdentity,
    ) {}

    public function handle(Nutgram $bot): void
    {
        $chat = $bot->chat();
        $message = $bot->message();
        if ($chat === null || $chat->type !== ChatType::PRIVATE || $message?->photo === null || $message->photo === []) {
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
        $originExternalId = $chatId.':'.$message->message_id;
        $existing = CompanionTurn::query()
            ->where('organization_id', $organization->getKey())
            ->where('origin_channel', 'telegram')
            ->where('origin_external_id', $originExternalId)
            ->first();
        if ($existing instanceof CompanionTurn) {
            return;
        }

        $photo = $this->largestPhoto(array_values($message->photo));
        $temporaryPath = tempnam(sys_get_temp_dir(), 'cc_telegram_');
        if ($temporaryPath === false) {
            $this->acceptUnavailable($client, $chatId, $originExternalId, $caption, $message->media_group_id, $message->message_id, $bot);

            return;
        }

        try {
            if ($photo->download($temporaryPath) !== true) {
                $this->acceptUnavailable($client, $chatId, $originExternalId, $caption, $message->media_group_id, $message->message_id, $bot);

                return;
            }
            $file = new UploadedFile($temporaryPath, 'telegram-'.$message->message_id.'.jpg', 'image/jpeg', UPLOAD_ERR_OK, true);
            $attachments = $this->upload->handle($client, [$file]);
            $attachment = $attachments[0] ?? null;
            if ($attachment === null) {
                $this->acceptUnavailable($client, $chatId, $originExternalId, $caption, $message->media_group_id, $message->message_id, $bot);

                return;
            }

            $this->accept->handle(
                client: $client,
                channel: 'telegram',
                body: $caption,
                idempotencyKey: null,
                originExternalId: $originExternalId,
                transportChatId: $chatId,
                locale: (string) ($user->language_code ?? 'en'),
                attachmentIds: [(int) $attachment->getKey()],
                mediaGroupId: $message->media_group_id,
                sourceOrdinal: $message->message_id,
                payloadHash: hash('sha256', json_encode([
                    'caption' => $caption,
                    'checksum' => $attachment->sha256_checksum,
                    'media_group_id' => $message->media_group_id,
                ], JSON_THROW_ON_ERROR)),
            );
        } catch (\Throwable) {
            $this->acceptUnavailable($client, $chatId, $originExternalId, $caption, $message->media_group_id, $message->message_id, $bot);
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /** @param list<PhotoSize> $photos */
    private function largestPhoto(array $photos): PhotoSize
    {
        usort($photos, static fn (PhotoSize $left, PhotoSize $right): int => ($right->width * $right->height) <=> ($left->width * $left->height));

        return $photos[0];
    }

    private function acceptUnavailable(
        Client $client,
        string $chatId,
        string $originExternalId,
        string $caption,
        ?string $mediaGroupId,
        int $messageId,
        Nutgram $bot,
    ): void {
        try {
            $this->accept->handle(
                client: $client,
                channel: 'telegram',
                body: $caption !== '' ? $caption : '[Изображение не удалось получить]',
                idempotencyKey: null,
                originExternalId: $originExternalId,
                transportChatId: $chatId,
                locale: (string) ($bot->user()->language_code ?? 'en'),
                mediaGroupId: $mediaGroupId,
                sourceOrdinal: $messageId,
                payloadHash: hash('sha256', $originExternalId.'|image-unavailable'),
                inputFailureCode: 'image_unavailable',
            );
        } catch (\Throwable) {
            $user = $bot->user();
            $language = $user instanceof TelegramUser ? ($user->language_code ?? '') : '';
            $bot->sendMessage(str_starts_with(strtolower((string) $language), 'ru')
                ? 'Не удалось принять изображение. Отправьте его ещё раз или напишите специалисту.'
                : 'The image could not be accepted. Please send it again or contact a specialist.');
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
