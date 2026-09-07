<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelReferralPayoutRequest;
use App\Http\Requests\RequestReferralPayoutRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Finance\Domain\Enums\CurrencyCode;
use App\Modules\Finance\Domain\ValueObjects\Money;
use App\Modules\Referrals\Application\RequestReferralPayout;
use App\Modules\Referrals\Application\TransitionReferralPayoutRequest;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class ReferralPayoutController extends Controller
{
    public function store(
        RequestReferralPayoutRequest $request,
        ClientPortalContext $context,
        RequestReferralPayout $action,
    ): RedirectResponse {
        try {
            $client = $context->client();
        } catch (LogicException) {
            abort(401);
        }

        try {
            $payout = $action->handle(
                client: $client,
                amount: (string) $request->validated('amount'),
                currency: (string) $request->validated('currency'),
                idempotencyKey: (string) $request->validated('idempotency_key'),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            return back()->withErrors([
                'payout' => 'Не удалось отправить заявку. Попробуйте ещё раз.',
            ]);
        }

        $currency = CurrencyCode::tryFrom((string) $payout->getRawOriginal('currency'));
        $amount = $currency === null
            ? '—'
            : Money::ofMinor((int) $payout->amount_minor, $currency)->toDecimalString().' '.$currency->value;

        return back()
            ->with('success', 'Заявка на выплату отправлена')
            ->with('payout_feedback', [
                'message' => 'Заявка на выплату отправлена',
                'amount' => $amount,
                'currency' => $currency?->value,
                'status' => ReferralPayoutRequestStatus::Requested->label(),
                'requested_at' => $payout->requested_at->toIso8601String(),
            ]);
    }

    public function cancel(
        int $payoutRequestId,
        CancelReferralPayoutRequest $request,
        ClientPortalContext $context,
        TransitionReferralPayoutRequest $transition,
    ): RedirectResponse {
        try {
            $client = $context->client();
        } catch (LogicException) {
            abort(401);
        }

        $transition->handle(
            request: $payoutRequestId,
            target: ReferralPayoutRequestStatus::Cancelled,
            actor: $client,
            idempotencyKey: (string) $request->validated('idempotency_key'),
        );

        return back()->with('success', 'Запрос на выплату отменён.');
    }
}
