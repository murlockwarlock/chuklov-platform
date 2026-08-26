<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ReferralRedirectController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        return to_route('portal.home');
    }
}
