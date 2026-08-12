<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Services\Application\ListPublishedServices;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

class ServiceIndexController extends Controller
{
    public function __invoke(
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
                'clientName' => $client?->full_name,
                'telegramAuthUrl' => '/portal/telegram/auth',
                'onboardingUrl' => route('portal.onboarding'),
            ],
        ]);
    }
}
