<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\StartClientOnboarding;
use App\Modules\Identity\Application\ConsumeTelegramWebAuthentication;
use App\Modules\Identity\Application\InitiateTelegramWebAuthentication;
use App\Modules\Identity\Application\InvalidTelegramWebAuthentication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TelegramWebAuthenticationController extends Controller
{
    public function request(Request $request, InitiateTelegramWebAuthentication $initiate): RedirectResponse
    {
        $browserBinding = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = $initiate->handle($browserBinding);
        $request->session()->put([
            'telegram_web_auth.request_id' => $challenge->request->getKey(),
            'telegram_web_auth.url' => $challenge->url,
            'telegram_web_auth.browser_binding' => $browserBinding,
        ]);

        return to_route('portal.services.index');
    }

    public function status(
        Request $request,
        ConsumeTelegramWebAuthentication $consume,
        StartClientOnboarding $startOnboarding,
    ): JsonResponse {
        $requestId = $request->session()->get('telegram_web_auth.request_id');
        $browserBinding = $request->session()->get('telegram_web_auth.browser_binding');

        if ((! is_int($requestId) && (! is_string($requestId) || ! ctype_digit($requestId)))
            || ! is_string($browserBinding)
            || $browserBinding === '') {
            return response()->json(['status' => 'expired'], 410);
        }

        try {
            $client = $consume->handle((int) $requestId, $browserBinding);
        } catch (InvalidTelegramWebAuthentication) {
            $request->session()->forget('telegram_web_auth');

            return response()->json(['status' => 'expired'], 410);
        }

        if ($client === null) {
            return response()->json(['status' => 'pending']);
        }

        $request->session()->regenerate();
        $request->session()->put('client_portal.client_id', $client->getKey());
        $request->session()->forget('telegram_web_auth');
        $startOnboarding->handle($client);

        return response()->json([
            'status' => 'authenticated',
            'redirect' => route('portal.onboarding'),
        ]);
    }
}
