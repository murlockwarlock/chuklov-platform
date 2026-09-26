<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\PortalClientMessages;
use App\Modules\ClientPortal\Application\PortalPaymentErrorMessages;
use App\Modules\Finance\Application\InitiateClientFakePayment;
use App\Modules\Finance\Application\InitiateClientLavaPayment;
use App\Modules\Finance\Application\ListClientFinance;
use App\Modules\Finance\Application\SimulateClientFakePayment;
use App\Modules\Finance\Domain\Exceptions\PaymentGatewayInitiationFailure;
use App\Modules\Referrals\Application\ApplyReferralCreditToObligation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FinanceController extends Controller
{
    public function index(ListClientFinance $finance): Response
    {
        return Inertia::render('Portal/Finance', [
            ...$finance->handle(app()->getLocale()),
            'urls' => [
                'home' => route('portal.home'),
                'bookings' => route('portal.bookings.index'),
            ],
        ]);
    }

    public function startDemoPayment(
        Request $request,
        InitiateClientFakePayment $initiate,
        int $obligationId,
    ): RedirectResponse {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:180', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        $initiate->handle($obligationId, (string) $data['idempotency_key']);

        return back();
    }

    public function applyReferralCredit(
        Request $request,
        ClientPortalContext $context,
        ApplyReferralCreditToObligation $apply,
        PortalClientMessages $messages,
        int $obligationId,
    ): RedirectResponse {
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:30', 'regex:/^(0|[1-9][0-9]*)(?:\.[0-9]+)?$/'],
            'currency' => ['required', 'string', 'max:3'],
            'idempotency_key' => ['required', 'string', 'max:180', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ], $messages->validationMessages('referral_credit'));

        try {
            $apply->handle(
                client: $context->client(),
                obligationId: $obligationId,
                amount: (string) $data['amount'],
                currency: (string) $data['currency'],
                idempotencyKey: (string) $data['idempotency_key'],
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($messages->validationException('referral_credit', $exception));
        }

        return back()->with('success', $messages->message('referral_credit_applied'));
    }

    public function startLavaPayment(
        Request $request,
        InitiateClientLavaPayment $initiate,
        PortalPaymentErrorMessages $paymentErrors,
        int $obligationId,
    ): RedirectResponse {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:180', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        try {
            $transaction = $initiate->handle(
                obligationId: $obligationId,
                idempotencyKey: (string) $data['idempotency_key'],
                successfulReturnUrl: route('portal.finance.index'),
                failureReturnUrl: route('portal.finance.index'),
                cancelReturnUrl: route('portal.finance.index'),
            );
        } catch (PaymentGatewayInitiationFailure $exception) {
            return back()->withErrors(['payment' => $paymentErrors->gateway($exception)]);
        } catch (ValidationException $exception) {
            return back()->withErrors(['payment' => $paymentErrors->validation($exception)]);
        }

        if (! is_string($transaction->checkout_url) || $transaction->checkout_url === '') {
            return back()->withErrors(['payment' => $paymentErrors->message('payment_checking')]);
        }

        return redirect()->away($transaction->checkout_url);
    }

    public function simulateDemoPayment(
        SimulateClientFakePayment $simulate,
        int $transactionId,
        string $outcome,
    ): RedirectResponse {
        $simulate->handle($transactionId, $outcome);

        return back();
    }
}
