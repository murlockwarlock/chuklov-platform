<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Enums\NotificationDeliveryOutcome;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\Channels\Domain\ValueObjects\NotificationMessage;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use Illuminate\Support\Facades\Log;

final class SendReferralPayoutStatusNotification
{
    public function __construct(private readonly NotificationChannelRegistry $channels) {}

    public function handle(ReferralPayoutRequest $request): NotificationDeliveryResult
    {
        $status = $request->status;
        if (! in_array($status, [
            ReferralPayoutRequestStatus::Approved,
            ReferralPayoutRequestStatus::Rejected,
            ReferralPayoutRequestStatus::Paid,
        ], true)) {
            return NotificationDeliveryResult::suppressed('payout_status_not_notifiable');
        }

        $identity = ClientChannelIdentity::query()
            ->where('organization_id', $request->organization_id)
            ->where('client_id', $request->beneficiary_client_id)
            ->where('channel', 'telegram')
            ->where('verification_status', ChannelIdentityStatus::Verified->value)
            ->first();
        $channel = $this->channels->get('telegram');

        if ($identity === null || $channel === null) {
            return NotificationDeliveryResult::unavailable('verified_telegram_unavailable');
        }

        $currency = CurrencyCode::tryFrom((string) $request->getRawOriginal('currency'));
        if ($currency === null) {
            return NotificationDeliveryResult::permanentFailure('payout_currency_unavailable');
        }

        $amount = Money::ofMinor((int) $request->amount_minor, $currency)->toDecimalString().' '.$currency->value;
        $locale = str_starts_with(strtolower((string) $identity->client->language), 'en') ? 'en' : 'ru';
        $body = $this->body($status, $amount, $request->rejection_reason, $locale);

        $result = $channel->send(new NotificationMessage(
            recipientExternalId: (string) $identity->external_id,
            body: $body,
            subject: null,
            locale: $locale,
            idempotencyKey: 'referral-payout:'.$request->organization_id.':'.$request->getKey().':'.$status->value,
        ));

        if ($result->outcome !== NotificationDeliveryOutcome::Delivered) {
            Log::warning('referral_payout_telegram_notification_not_delivered', [
                'organization_id' => $request->organization_id,
                'payout_request_id' => $request->getKey(),
                'status' => $status->value,
                'outcome' => $result->outcome->value,
                'error_code' => $result->errorCode,
            ]);
        }

        return $result;
    }

    private function body(ReferralPayoutRequestStatus $status, string $amount, ?string $reason, string $locale): string
    {
        if ($locale === 'en') {
            return match ($status) {
                ReferralPayoutRequestStatus::Approved => 'Payout request '.$amount.' was approved.',
                ReferralPayoutRequestStatus::Rejected => 'Payout request was rejected: '.$this->safeReason($reason, 'the submitted details need checking').'.',
                ReferralPayoutRequestStatus::Paid => 'Payout '.$amount.' was marked as paid.',
                default => '',
            };
        }

        return match ($status) {
            ReferralPayoutRequestStatus::Approved => 'Заявка на выплату '.$amount.' одобрена.',
            ReferralPayoutRequestStatus::Rejected => 'Заявка на выплату отклонена: '.$this->safeReason($reason, 'проверьте реквизиты').'.',
            ReferralPayoutRequestStatus::Paid => 'Выплата '.$amount.' отмечена как выполненная.',
            default => '',
        };
    }

    private function safeReason(?string $reason, string $fallback): string
    {
        $reason = trim(strip_tags((string) $reason));
        $reason = preg_replace('/\s+/u', ' ', $reason) ?? '';

        return mb_substr($reason === '' ? $fallback : $reason, 0, 300);
    }
}
