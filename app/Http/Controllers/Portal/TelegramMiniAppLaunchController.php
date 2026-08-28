<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use Illuminate\Http\RedirectResponse;
use LogicException;

final class TelegramMiniAppLaunchController extends Controller
{
    public function __invoke(
        string $entry,
        ResolveTelegramMiniAppEntry $resolver,
        ClientPortalContext $clientContext,
    ): RedirectResponse {
        $destination = $resolver->destination($entry);

        if (! $resolver->requiresAuthentication($entry)) {
            return redirect()->to($destination);
        }

        try {
            $clientContext->client();
        } catch (LogicException) {
            return redirect()->to(route('portal.home', ['telegram_entry' => $entry], false));
        }

        return redirect()->to($destination);
    }
}
