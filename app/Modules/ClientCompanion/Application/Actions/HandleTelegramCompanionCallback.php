<?php

namespace App\Modules\ClientCompanion\Application\Actions;

use App\Modules\ClientCompanion\Domain\Enums\CompanionFeedbackValue;
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
