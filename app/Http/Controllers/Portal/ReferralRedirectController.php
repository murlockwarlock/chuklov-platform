<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Referrals\Application\RecordReferralLinkVisit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ReferralRedirectController extends Controller
{
    public function __invoke(Request $request, RecordReferralLinkVisit $visits): RedirectResponse
    {
        $visits->handle((string) $request->route('referralCode'), $request->session()->getId());

        return to_route('portal.home');
    }
}
