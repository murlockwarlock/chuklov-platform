<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Attribution\Application\GetClientAttribution;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\ProjectPortalService;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Scheduling\Application\ListClientBookings;
use App\Modules\Services\Application\ListPublishedServices;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class HomeController extends Controller
{
    public function __invoke(
        Request $request,
        ClientPortalContext $clientContext,
        ListPublishedServices $services,
        ListClientBookings $bookings,
        ProjectPortalService $serviceProjection,
        GetClientAttribution $getAttribution,
    ): Response {
        try {
            $client = $clientContext->client();
        } catch (LogicException) {
            $client = null;
        }

        if (! $client instanceof Client) {
            return Inertia::render('Portal/Entry', [
                'auth' => [
                    'telegramAuthUrl' => route('portal.telegram.auth'),
                    'telegramAuthError' => $request->session()->pull('telegram_auth_error'),
                    'telegramWebRequestUrl' => route('portal.telegram.web.request'),
                    'telegramWebStatusUrl' => route('portal.telegram.web.status'),
                    'telegramWebUrl' => $request->session()->get('telegram_web_auth.url'),
                    'emailRequestUrl' => route('portal.email.request'),
                    'emailVerifyUrl' => route('portal.email.verify'),
                    'emailCodeSent' => (bool) $request->session()->pull('email_code_sent', false),
                ],
            ]);
        }

        $upcoming = $bookings->handle(app()->getLocale())['upcoming'];

        return Inertia::render('Portal/Home', [
            'upcomingBooking' => $upcoming[0] ?? null,
            'services' => $services->handle()
                ->map(fn ($service): array => $serviceProjection->handle($service, app()->getLocale()))
                ->values()
                ->all(),
            'attribution' => [
                'needsManualSource' => $getAttribution->handle($client) === null,
            ],
        ]);
    }
}
