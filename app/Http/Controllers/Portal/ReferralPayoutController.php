<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelReferralPayoutRequest;
use App\Http\Requests\RequestReferralPayoutRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Referrals\Application\RequestReferralPayout;
use App\Modules\Referrals\Application\TransitionReferralPayoutRequest;
use App\Modules\Referrals\Domain\Enums\ReferralPayoutRequestStatus;
use Illuminate\Http\RedirectResponse;
use LogicException;

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

        $action->handle(
            client: $client,
            amount: (string) $request->validated('amount'),
            currency: (string) $request->validated('currency'),
            idempotencyKey: (string) $request->validated('idempotency_key'),
        );

        return back()->with('success', 'Запрос на выплату отправлен.');
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
