<?php

namespace App\Modules\Referrals\Application;

use App\Models\User;
use App\Modules\Finance\Application\FinanceAuthorization;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequest;
use App\Modules\Referrals\Domain\Models\ReferralPayoutRequestEvent;
use App\Modules\Security\Application\RecordAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TransitionReferralPayoutRequest
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly FinanceAuthorization $authorization,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function handle(
        ReferralPayoutRequest|int $request,
        ReferralPayoutRequestStatus $target,
        User|Client $actor,
        string $idempotencyKey,
        ?string $reason = null,
        ?string $paymentNote = null,
        ?string $paymentReference = null,
    ): ReferralPayoutRequest {
        $requestId = $request instanceof ReferralPayoutRequest ? (int) $request->getKey() : $request;
        $user = $actor instanceof User ? $actor : null;
        $actorType = $actor instanceof User ? 'user' : 'client';

        if ($actor instanceof Client && (int) $actor->organization_id !== $this->context->id()) {
            throw new AuthorizationException('The client is outside the current organization.');
        }

        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === ''
            || strlen($idempotencyKey) > 120
            || preg_match('/^[A-Za-z0-9_-]+$/', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Укажите корректный ключ повторного запроса.',
            ]);
        }

        $reason = $this->nullableTrim($reason, 'reason', 2000);
        $paymentNote = $this->nullableTrim($paymentNote, 'payment_note', 2000);
        $paymentReference = $this->nullableTrim($paymentReference, 'payment_reference', 180);

        if ($target !== ReferralPayoutRequestStatus::Paid
            && ($paymentNote !== null || $paymentReference !== null)) {
            throw ValidationException::withMessages([
                'payment_reference' => 'Платёжную пометку можно указать только при отметке выплаты.',
            ]);
        }

        if ($user instanceof User) {
            $this->authorization->authorizeManage($user);
        }

        return DB::transaction(function () use ($requestId, $target, $actor, $user, $actorType, $idempotencyKey, $reason, $paymentNote, $paymentReference): ReferralPayoutRequest {
            $candidate = ReferralPayoutRequest::query()
                ->where('organization_id', $this->context->id())
                ->whereKey($requestId)
                ->first();

            if (! $candidate instanceof ReferralPayoutRequest) {
                throw new AuthorizationException('The payout request is outside the current organization.');
            }

            $beneficiary = Client::query()
                ->where('organization_id', $this->context->id())
                ->whereKey($candidate->beneficiary_client_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = ReferralPayoutRequest::query()
                ->where('organization_id', $this->context->id())
                ->whereKey($candidate->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($actor instanceof Client && (int) $beneficiary->getKey() !== (int) $actor->getKey()) {
                throw new AuthorizationException('The payout request belongs to another client.');
            }

            if ($actor instanceof Client && $target !== ReferralPayoutRequestStatus::Cancelled) {
                throw new AuthorizationException('A client can only cancel a payout request.');
            }

            $requestHash = $this->requestHash($locked, $target, $reason, $paymentNote, $paymentReference);
            $existingEvent = ReferralPayoutRequestEvent::query()
                ->where('organization_id', $this->context->id())
                ->where('payout_request_id', $locked->getKey())
                ->where('idempotency_key', 'payout.'.$locked->getKey().'.'.$idempotencyKey)
                ->first();

            if ($existingEvent instanceof ReferralPayoutRequestEvent) {
                if (! hash_equals((string) $existingEvent->request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Этот запрос уже использован для другой операции.',
                    ]);
                }

                return $locked;
            }

            $current = ReferralPayoutRequestStatus::tryFrom((string) $locked->getRawOriginal('status'));

            if ($current === null) {
                throw ValidationException::withMessages(['status' => 'Статус запроса недоступен.']);
            }

            if ($current === $target) {
                return $locked;
            }

            $this->assertTransition($current, $target, $reason);
            $this->applyTransition($locked, $target, $user, $reason, $paymentNote, $paymentReference);
            $locked->save();
            $this->recordEvent($locked, $current, $target, $actorType, $user?->getKey(), $reason, $paymentNote, $paymentReference, $idempotencyKey, $requestHash);
            $organization = $this->context->organization();
            $this->audit->handle(
                organization: $organization,
                actor: $user,
                action: 'referral.payout.'.$target->value,
                targetType: ReferralPayoutRequest::class,
                targetId: (string) $locked->getKey(),
                metadata: [
                    'beneficiary_client_id' => $beneficiary->getKey(),
                    'amount_minor' => $locked->amount_minor,
                    'currency' => CurrencyCode::from((string) $locked->getRawOriginal('currency'))->value,
                    'from_status' => $current->value,
                    'to_status' => $target->value,
                    'reason_present' => is_string($reason) && trim($reason) !== '',
                    'payment_reference_present' => is_string($paymentReference) && trim($paymentReference) !== '',
                    'actor_type' => $actorType,
                    'actor_client_id' => $actor instanceof Client ? $actor->getKey() : null,
                ],
            );

            return $locked->refresh();
        });
    }

    private function assertTransition(
        ReferralPayoutRequestStatus $current,
        ReferralPayoutRequestStatus $target,
        ?string $reason,
    ): void {
        if ($target === ReferralPayoutRequestStatus::Rejected && (! is_string($reason) || trim($reason) === '')) {
            throw ValidationException::withMessages(['reason' => 'Укажите причину отклонения.']);
        }

        $allowed = match ($current) {
            ReferralPayoutRequestStatus::Requested => [
                ReferralPayoutRequestStatus::Approved,
                ReferralPayoutRequestStatus::Rejected,
                ReferralPayoutRequestStatus::Cancelled,
            ],
            ReferralPayoutRequestStatus::Approved => [
                ReferralPayoutRequestStatus::Paid,
                ReferralPayoutRequestStatus::Rejected,
            ],
            default => [],
        };

        if (! in_array($target, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'Этот переход статуса недоступен.']);
        }

    }

    private function applyTransition(
        ReferralPayoutRequest $request,
        ReferralPayoutRequestStatus $target,
        ?User $user,
        ?string $reason,
        ?string $paymentNote,
        ?string $paymentReference,
    ): void {
        $attributes = ['status' => $target->value, 'updated_at' => now()];

        if ($target === ReferralPayoutRequestStatus::Approved) {
            $attributes['approved_by_user_id'] = $user?->getKey();
            $attributes['approved_at'] = now();
        }

        if ($target === ReferralPayoutRequestStatus::Rejected) {
            $attributes['rejected_by_user_id'] = $user?->getKey();
            $attributes['rejected_at'] = now();
            $attributes['rejection_reason'] = trim((string) $reason);
        }

        if ($target === ReferralPayoutRequestStatus::Cancelled) {
            $attributes['cancelled_by_user_id'] = $user?->getKey();
            $attributes['cancelled_at'] = now();
        }

        if ($target === ReferralPayoutRequestStatus::Paid) {
            $attributes['paid_by_user_id'] = $user?->getKey();
            $attributes['paid_at'] = now();
            $attributes['payment_note'] = $this->nullableTrim($paymentNote);
            $attributes['payment_reference'] = $this->nullableTrim($paymentReference);
        }

        $request->forceFill($attributes);
    }

    private function recordEvent(
        ReferralPayoutRequest $request,
        ReferralPayoutRequestStatus $from,
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
            'from_status' => $from->value,
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

    private function requestHash(
        ReferralPayoutRequest $request,
        ReferralPayoutRequestStatus $target,
        ?string $reason,
        ?string $paymentNote,
        ?string $paymentReference,
    ): string {
        return hash('sha256', json_encode([
            'request_id' => $request->getKey(),
            'target' => $target->value,
            'reason' => trim((string) $reason),
            'payment_note' => trim((string) $paymentNote),
            'payment_reference' => trim((string) $paymentReference),
        ], JSON_THROW_ON_ERROR));
    }

    private function nullableTrim(?string $value, string $field = 'value', int $maxLength = 2000): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        if (mb_strlen($value) > $maxLength) {
            throw ValidationException::withMessages([$field => 'Значение слишком длинное.']);
        }

        return $value === '' ? null : $value;
    }
}
