<?php

namespace App\Http\Middleware;

use App\Modules\Attribution\Application\CapturePreAuthAttribution as CapturePreAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CapturePortalAttribution
{
    public function __construct(private readonly CapturePreAuth $capture) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('client_portal.client_id')) {
            $input = $request->query();
            $referralCode = $request->route('referralCode');

            if (is_string($referralCode)) {
                $input['referral_code'] = $referralCode;
            }

            $this->capture->handle(
                sessionId: $request->session()->getId(),
                input: $input,
                captureChannel: 'portal',
                captureContext: $request->routeIs('portal.referral') ? 'referral_link' : 'portal_entry',
            );
        }

        return $next($request);
    }
}
