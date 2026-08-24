<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\ClientCompanion\Domain\Enums\CompanionFeedbackValue;
use App\Modules\ClientCompanion\Domain\Enums\CompanionImageReferenceMode;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use SergiX44\Nutgram\Nutgram;
use Throwable;

final class HandleTelegramCompanionCallback
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AcceptCompanionMessage $accept,
        private readonly RecordCompanionFeedback $feedback,
        private readonly RequestCompanionHandoff $handoff,
    ) {}

    public function handle(Nutgram $bot): void
    {
        $data = (string) ($bot->callbackQuery()->data ?? '');
        if (preg_match('/^cc:feedback:(helpful|not_helpful):(\d+)$/', $data, $feedbackMatch) === 1) {
            $action = 'feedback';
            $messageId = (int) $feedbackMatch[2];
            $value = CompanionFeedbackValue::from($feedbackMatch[1]);
        } elseif (preg_match('/^cc:human:(\d+)$/', $data, $handoffMatch) === 1) {
            $action = 'human';
            $messageId = (int) $handoffMatch[1];
            $value = null;
        } elseif (preg_match('/^cc:reinspect:(\d+)$/', $data, $reinspectMatch) === 1) {
            $action = 'reinspect';
            $messageId = (int) $reinspectMatch[1];
            $value = null;
        } else {
            $bot->answerCallbackQuery(text: 'Действие недоступно.');

            return;
        }

        $organization = $this->organization();
        $user = $bot->user();
        if ($organization === null || $user === null) {
            $bot->answerCallbackQuery(text: 'Сначала подключите Telegram к порталу.');

            return;
        }
        $this->context->set($organization);
        $identity = ClientChannelIdentity::query()
            ->where('organization_id', $organization->getKey())
            ->where('channel', 'telegram')
            ->where('external_id', (string) $user->id)
            ->where('verification_status', ChannelIdentityStatus::Verified)
            ->first();
        $client = $identity?->client;
        if (! $client instanceof Client || (int) $client->organization_id !== (int) $organization->getKey()) {
            $bot->answerCallbackQuery(text: 'Сначала подключите Telegram к порталу.');

            return;
        }

        try {
            if ($action === 'feedback') {
                $this->feedback->handle($client, $messageId, $value);
                $bot->answerCallbackQuery(text: $value === CompanionFeedbackValue::Helpful ? 'Спасибо!' : 'Спасибо за обратную связь.');
            } elseif ($action === 'reinspect') {
                $chatId = $bot->chat()?->id;
                if ($chatId === null) {
                    throw new \RuntimeException('Telegram chat is unavailable.');
                }
                $this->accept->handle(
                    client: $client,
                    channel: 'telegram',
                    body: str_starts_with(strtolower((string) $user->language_code), 'ru')
                        ? 'Уточните, пожалуйста, по предыдущему фото.'
                        : 'Please clarify the previous photo.',
                    idempotencyKey: null,
                    originExternalId: 'reinspect:'.$user->id.':'.$messageId,
                    transportChatId: (string) $chatId,
                    locale: (string) ($user->language_code ?? 'en'),
                    payloadHash: hash('sha256', 'reinspect|'.$client->getKey().'|'.$messageId),
                    imageReferenceMode: CompanionImageReferenceMode::RecentTurn,
                    imageReferenceMessageId: $messageId,
                );
                $bot->answerCallbackQuery(text: 'Уточнение принято.');
            } else {
                $this->handoff->handle($client, $messageId);
                $bot->answerCallbackQuery(text: 'Запрос передан специалисту.');
            }
            try {
                $bot->editMessageReplyMarkup(reply_markup: null);
            } catch (Throwable) {
            }
        } catch (Throwable) {
            $bot->answerCallbackQuery(text: 'Действие больше недоступно.');
        }
    }

    private function organization(): ?Organization
    {
        $id = config('tenancy.default_organization_id');
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        return Organization::query()->find((int) $id);
    }
}
