<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Attribution\Application\CapturePreAuthAttribution;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\Channels\Infrastructure\Telegram\InvalidTelegramInitData;
use App\Modules\Channels\Infrastructure\Telegram\TelegramInitDataVerifier;
use App\Modules\ClientPortal\Application\ApplyClientPortalLocale;
use App\Modules\ClientPortal\Application\StartClientOnboarding;
use App\Modules\Identity\Application\AuthenticateClientWithVerifiedChannel;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Referrals\Application\FinalizeClientAcquisition;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class TelegramAuthenticationController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramInitDataVerifier $verifier,
        AuthenticateClientWithVerifiedChannel $authenticate,
        StartClientOnboarding $startOnboarding,
        ApplyClientPortalLocale $applyLocale,
        CapturePreAuthAttribution $captureAttribution,
        FinalizeClientAcquisition $finalizeAcquisition,
        ResolveTelegramMiniAppEntry $telegramEntries,
    ): RedirectResponse {
        $validated = $request->validate([
            'initData' => ['required', 'string', 'max:8192'],
            'launchEntry' => ['nullable', 'string', 'max:64'],
            'clientTimezone' => ['nullable', 'string', 'max:64'],
        ]);
        $launchEntry = $validated['launchEntry'] ?? null;
        $launchEntry = is_string($launchEntry) && trim($launchEntry) !== '' ? trim($launchEntry) : null;
        $destination = $launchEntry === null ? route('portal.home') : $telegramEntries->destinationOrNull($launchEntry);

        if ($destination === null) {
            $launchEntry = null;
            $destination = route('portal.home');
        }

        try {
            $identity = $verifier->handle($validated['initData']);
            if ($identity->startParameter !== null) {
                $captureAttribution->handle(
                    sessionId: $request->session()->getId(),
                    input: ['referral_code' => $identity->startParameter],
                    captureChannel: 'telegram_mini_app',
                    captureContext: 'telegram_start_param',
                );
            }
            $client = $authenticate->handle(
                verifiedIdentity: $identity,
                acquisitionSessionId: $request->session()->getId(),
                clientTimezone: $validated['clientTimezone'] ?? null,
            );
        } catch (InvalidTelegramInitData|AuthorizationException) {
            $errorRedirect = $launchEntry === null
                ? to_route('portal.home')
                : redirect()->to(route('portal.home', ['telegram_entry' => $launchEntry], false));

            return $errorRedirect->with(
                'telegram_auth_error',
                $this->localizedAuthError($request),
            );
        }

        $finalizeAcquisition->handle($client, $request->session()->getId());
        $request->session()->regenerate();
        $request->session()->put('client_portal.client_id', $client->getKey());
        $this->applySessionLocale($request, $client, $applyLocale);
        $startOnboarding->handle($client);

        return redirect()->to($destination);
    }

    private function applySessionLocale(
        Request $request,
        Client $client,
        ApplyClientPortalLocale $applyLocale,
    ): void {
        $locale = $request->session()->get('portal.locale');

        if (is_string($locale) && in_array($locale, ['ru', 'en'], true)) {
            $applyLocale->handle($client, $locale);
        }
    }

    private function localizedAuthError(Request $request): string
    {
        return $request->session()->get('portal.locale') === 'en'
            ? 'Telegram sign-in failed. Close the app and open it again.'
            : 'Не удалось войти через Telegram. Закройте приложение и откройте его снова.';
    }
}
