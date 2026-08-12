<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Channels\Infrastructure\Telegram\InvalidTelegramInitData;
use App\Modules\Channels\Infrastructure\Telegram\TelegramInitDataVerifier;
use App\Modules\ClientPortal\Application\StartClientOnboarding;
use App\Modules\Identity\Application\AuthenticateClientWithVerifiedChannel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class TelegramAuthenticationController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramInitDataVerifier $verifier,
        AuthenticateClientWithVerifiedChannel $authenticate,
        StartClientOnboarding $startOnboarding,
    ): RedirectResponse {
        $validated = $request->validate([
            'initData' => ['required', 'string', 'max:8192'],
        ]);

        try {
            $identity = $verifier->handle($validated['initData']);
        } catch (InvalidTelegramInitData) {
            abort(403);
        }

        $client = $authenticate->handle($identity);
        $request->session()->regenerate();
        $request->session()->put('client_portal.client_id', $client->getKey());
        $startOnboarding->handle($client);

        return to_route('portal.onboarding');
    }
}
