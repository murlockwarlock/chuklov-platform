<?php

namespace App\Modules\Channels\Application;

use App\Models\User;
use App\Modules\Channels\Infrastructure\Telegram\TelegramBotIdentityVerifier;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\OrganizationChannelIdentity;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scheduling\Application\ConfirmBooking;
use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use App\Modules\Scheduling\Domain\Enums\VisitFormat;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Specialists\Domain\Models\Specialist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

final readonly class HandleTelegramBookingConfirmation
{
    public function __construct(
        private OrganizationContext $context,
        private TelegramBotIdentityVerifier $identityVerifier,
        private ConfirmBooking $confirmBooking,
    ) {}

    public function handle(Nutgram $bot): void
    {
        $data = (string) ($bot->callbackQuery()->data ?? '');
        if (preg_match('/^booking:confirm:(\d+)(?::(\d+))?$/', $data, $matches) !== 1) {
            $bot->answerCallbackQuery(text: 'Действие недоступно.');

            return;
        }

        $expectedEventVersion = isset($matches[2]) ? (int) $matches[2] : null;

        $organization = $this->organization();
        if (! $organization instanceof Organization) {
            $bot->answerCallbackQuery(text: 'Откройте запись в CRM.');

            return;
        }

        try {
            $this->context->set($organization);
            $identity = $this->identityVerifier->handle($bot);
            $organizationIdentity = OrganizationChannelIdentity::query()
                ->where('organization_id', $organization->getKey())
                ->where('channel', 'telegram')
                ->where('external_id', $identity->externalId)
                ->where('verification_status', ChannelIdentityStatus::Verified->value)
                ->first();
            $user = $organizationIdentity?->user;
            $specialist = $user instanceof User
                ? Specialist::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('staff_user_id', $user->getKey())
                    ->first()
                : null;
            $booking = $specialist instanceof Specialist
                ? Booking::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('specialist_id', $specialist->getKey())
                    ->whereKey((int) $matches[1])
                    ->first()
                : null;

            if (! $user instanceof User || ! $booking instanceof Booking) {
                $bot->answerCallbackQuery(text: 'Действие недоступно. Откройте CRM.');

                return;
            }

            if ($booking->status === BookingStatus::Confirmed) {
                $bot->answerCallbackQuery(text: 'Запись уже подтверждена.');

                return;
            }

            if ($booking->status !== BookingStatus::Requested
                || ! in_array($booking->visit_format, [VisitFormat::Office, VisitFormat::Online], true)) {
                $bot->answerCallbackQuery(text: 'Состояние записи изменилось. Откройте CRM.');

                return;
            }

            $this->confirmBooking->handle($user, $booking, expectedEventVersion: $expectedEventVersion);
            $bot->answerCallbackQuery(text: '✅ Запись подтверждена');
            try {
                $bot->editMessageReplyMarkup(reply_markup: null);
            } catch (Throwable) {
            }
        } catch (UnauthorizedHttpException) {
            $bot->answerCallbackQuery(text: 'Сначала подтвердите Telegram для CRM.');
        } catch (AuthorizationException) {
            $bot->answerCallbackQuery(text: 'Действие недоступно. Откройте CRM.');
        } catch (ValidationException) {
            $currentBooking = Booking::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey((int) $matches[1])
                ->first();

            $bot->answerCallbackQuery(
                text: $currentBooking?->status === BookingStatus::Confirmed
                    ? 'Запись уже подтверждена.'
                    : 'Состояние записи изменилось. Откройте CRM.',
            );
        } catch (Throwable) {
            $bot->answerCallbackQuery(text: 'Состояние записи изменилось. Откройте CRM.');
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
