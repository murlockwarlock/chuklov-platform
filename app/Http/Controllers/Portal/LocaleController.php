<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\SetPortalLocaleRequest;
use App\Modules\ClientPortal\Application\ApplyClientPortalLocale;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use Illuminate\Http\RedirectResponse;
use LogicException;

class LocaleController extends Controller
{
    public function __invoke(
        SetPortalLocaleRequest $request,
        ClientPortalContext $clientContext,
        ApplyClientPortalLocale $applyLocale,
    ): RedirectResponse {
        $locale = (string) $request->validated('locale');

        try {
            $applyLocale->handle($clientContext->client(), $locale);
        } catch (LogicException) {
            $request->session()->put('portal.locale', $locale);
        }

        $request->session()->put('portal.locale', $locale);
        app()->setLocale($locale);

        return back();
    }
}
