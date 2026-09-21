<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelReferralPayoutRequest;
use App\Http\Requests\RequestReferralPayoutRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\PortalClientMessages;
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
        PortalClientMessages $messages,
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
            throw ValidationException::withMessages($messages->validationException('payout', $exception));
        } catch (Throwable) {
            return back()->withErrors([
                'payout' => $messages->message('referral_payout_failed'),
            ]);
        }

        $currency = CurrencyCode::tryFrom((string) $payout->getRawOriginal('currency'));
        $amount = $currency === null
            ? '—'
            : Money::ofMinor((int) $payout->amount_minor, $currency)->toDecimalString().' '.$currency->value;

        return back()
            ->with('success', $messages->message('referral_payout_requested'))
            ->with('payout_feedback', [
                'message' => $messages->message('referral_payout_requested'),
                'amount' => $amount,
                'currency' => $currency?->value,
                'status' => $messages->message('referral_payout_requested'),
                'requested_at' => $payout->requested_at->toIso8601String(),
            ]);
    }

    public function cancel(
        int $payoutRequestId,
        CancelReferralPayoutRequest $request,
        ClientPortalContext $context,
        TransitionReferralPayoutRequest $transition,
        PortalClientMessages $messages,
    ): RedirectResponse {
        try {
            $client = $context->client();
        } catch (LogicException) {
            abort(401);
        }

        try {
            $transition->handle(
                request: $payoutRequestId,
                target: ReferralPayoutRequestStatus::Cancelled,
                actor: $client,
                idempotencyKey: (string) $request->validated('idempotency_key'),
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($messages->validationException('payout', $exception));
        }

        return back()->with('success', $messages->message('referral_payout_cancelled'));
    }
}
