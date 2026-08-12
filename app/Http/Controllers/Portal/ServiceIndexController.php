<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Domain\Enums\ChannelIdentityStatus;
use App\Modules\Services\Application\ListPublishedServices;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class ServiceIndexController extends Controller
{
    public function __invoke(
        Request $request,
        ListPublishedServices $services,
        ClientPortalContext $clientContext,
    ): Response {
        try {
            $client = $clientContext->client();
        } catch (LogicException) {
            $client = null;
        }

        return Inertia::render('Services/Index', [
            'services' => $services->handle()->map->only(['id', 'name', 'summary']),
            'runtimeMode' => 'web',
            'portal' => [
                'authenticated' => $client !== null,
                'clientName' => $client === null ? null : ($client->full_name ?? $client->email),
                'telegramAuthUrl' => '/portal/telegram/auth',
                'emailRequestUrl' => route('portal.email.request'),
                'emailVerifyUrl' => route('portal.email.verify'),
                'emailCodeSent' => (bool) $request->session()->pull('email_code_sent', false),
                'telegramConnected' => $client !== null
                    && $client->channelIdentities()
                        ->where('channel', 'telegram')
                        ->where('verification_status', ChannelIdentityStatus::Verified)
                        ->exists(),
                'telegramLinkRequestUrl' => route('portal.telegram.link'),
                'telegramLinkUrl' => $client !== null ? $request->session()->pull('telegram_link_url') : null,
                'telegramLinkError' => $client !== null ? (bool) $request->session()->pull('telegram_link_error', false) : false,
                'onboardingUrl' => route('portal.onboarding'),
            ],
        ]);
    }
}
