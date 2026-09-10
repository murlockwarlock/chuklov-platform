<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Application\InitiateClientFakePayment;
use App\Modules\Finance\Application\ListClientFinance;
use App\Modules\Finance\Application\SimulateClientFakePayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function simulateDemoPayment(
        SimulateClientFakePayment $simulate,
        int $transactionId,
        string $outcome,
    ): RedirectResponse {
        $simulate->handle($transactionId, $outcome);

        return back();
    }
}
