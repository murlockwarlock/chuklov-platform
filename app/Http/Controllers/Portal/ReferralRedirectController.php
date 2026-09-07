<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Referrals\Application\BuildReferralTelegramUrl;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ReferralRedirectController extends Controller
{
    public function __invoke(Request $request, BuildReferralTelegramUrl $telegramUrl): RedirectResponse
    {
        return redirect()->away($telegramUrl->handle((string) $request->route('referralCode')));
    }
}
