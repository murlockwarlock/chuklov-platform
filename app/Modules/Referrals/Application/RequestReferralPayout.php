<?php

namespace App\Modules\Referrals\Application;

use App\Modules\Finance\Domain\Services\CurrencyCatalog;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequestEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class RequestReferralPayout
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly CurrencyCatalog $catalog,
        private readonly ReferralRewardBalanceProjection $balances,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(Client $client, string $amount, string $currency, string $idempotencyKey): ReferralPayoutRequest
    {
        if ((int) $client->organization_id !== $this->context->id()) {
            abort(403);
        }

        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === ''
            || strlen($idempotencyKey) > 120
            || preg_match('/^[A-Za-z0-9_-]+$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Укажите корректный ключ повторного запроса.',
            ]);
        }

        $money = $this->money($amount, $currency);
        $requestHash = $this->requestHash($client, $money);

        return DB::transaction(function () use ($client, $money, $idempotencyKey, $requestHash): ReferralPayoutRequest {
            $beneficiary = Client::query()
                ->where('organization_id', $this->context->id())
                ->whereKey($client->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $existing = ReferralPayoutRequest::query()
                ->where('organization_id', $this->context->id())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing instanceof ReferralPayoutRequest) {
                if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Этот запрос уже использован для другой суммы или валюты.',
                    ]);
                }

                return $existing;
            }

            $available = $this->balances->forCurrency($beneficiary, $money->currency())->available();

            if ($money->compareTo($available) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Сумма превышает доступный остаток.',
                ]);
            }

            $organization = $this->context->organization();
            $inserted = DB::table('referral_payout_requests')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'beneficiary_client_id' => $beneficiary->getKey(),
                'amount_minor' => $money->minorUnits(),
                'currency' => $money->currency()->value,
                'status' => ReferralPayoutRequestStatus::Requested->value,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'requested_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $request = ReferralPayoutRequest::query()
                ->where('organization_id', $this->context->id())
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();

            if ($inserted === 0) {
                if (! hash_equals((string) $request->request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Этот запрос уже использован для другой суммы или валюты.',
                    ]);
                }

                return $request;
            }

            $this->recordEvent($request, null, ReferralPayoutRequestStatus::Requested, 'client', null, null, null, null, $idempotencyKey, $requestHash);
            $this->audit->handle(
                organization: $organization,
                actor: null,
                action: 'referral.payout.requested',
                targetType: ReferralPayoutRequest::class,
                targetId: (string) $request->getKey(),
                metadata: [
                    'beneficiary_client_id' => $beneficiary->getKey(),
                    'amount_minor' => $money->minorUnits(),
                    'currency' => $money->currency()->value,
                    'actor_type' => 'client',
                    'actor_client_id' => $beneficiary->getKey(),
                ],
            );

            return $request->refresh();
        });
    }

    private function money(string $amount, string $currency): Money
    {
        try {
            $code = $this->catalog->code($currency);
            $separator = strrpos($amount, '.');
            $fraction = $separator === false ? '' : substr($amount, $separator + 1);

            if (strlen($fraction) > $this->catalog->scale($code)) {
                throw new InvalidArgumentException('The payout amount has unsupported precision.');
            }

            $money = Money::fromDecimal($amount, $code);
            $money->assertPositive();

            return $money;
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'amount' => 'Укажите положительную сумму в допустимом формате.',
                'currency' => 'Выберите допустимую валюту.',
            ]);
        }
    }

    private function requestHash(Client $client, Money $money): string
    {
        return hash('sha256', $client->getKey().'|'.$money->minorUnitsString().'|'.$money->currency()->value);
    }

    private function recordEvent(
        ReferralPayoutRequest $request,
        ?ReferralPayoutRequestStatus $from,
        ReferralPayoutRequestStatus $to,
        string $actorType,
        ?int $actorUserId,
        ?string $reason,
        ?string $paymentNote,
        ?string $paymentReference,
        string $idempotencyKey,
        string $requestHash,
    ): void {
        $event = new ReferralPayoutRequestEvent;
        $event->forceFill([
            'organization_id' => $request->organization_id,
            'payout_request_id' => $request->getKey(),
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_user_id' => $actorUserId,
            'actor_type' => $actorType,
            'reason' => $reason,
            'payment_note' => $paymentNote,
            'payment_reference' => $paymentReference,
            'idempotency_key' => 'payout.'.$request->getKey().'.'.$idempotencyKey,
            'request_hash' => $requestHash,
            'occurred_at' => now(),
        ]);
        $event->save();
    }
}
