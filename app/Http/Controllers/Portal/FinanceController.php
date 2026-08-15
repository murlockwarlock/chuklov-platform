<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Application\ListClientFinance;
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
}
