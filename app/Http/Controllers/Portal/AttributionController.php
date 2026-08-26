<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordManualAttributionRequest;
use App\Modules\Attribution\Application\AcceptManualAttribution;
use App\Modules\Attribution\Application\GetClientAttribution;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AttributionController extends Controller
{
    public function show(ClientPortalContext $context, GetClientAttribution $get): Response
    {
        try {
            $client = $context->client();
        } catch (LogicException) {
            abort(401);
        }

        return Inertia::render('Portal/Attribution', [
            'needsManualSource' => $get->handle($client) === null,
            'sources' => config('attribution.manual_sources', []),
        ]);
    }

    public function update(
        RecordManualAttributionRequest $request,
        ClientPortalContext $context,
        AcceptManualAttribution $accept,
    ): RedirectResponse {
        try {
            $client = $context->client();
        } catch (LogicException) {
            abort(401);
        }

        $accept->handle($client, (string) $request->validated('source'));

        return to_route('portal.home');
    }
}
