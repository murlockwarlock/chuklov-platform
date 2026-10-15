<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Channels\Application\ResolveTelegramMiniAppEntry;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

final class TelegramMiniAppLaunchController extends Controller
{
    public function __invoke(
        string $entry,
        Request $request,
        ResolveTelegramMiniAppEntry $resolver,
        ClientPortalContext $clientContext,
    ): RedirectResponse {
        if ($entry === 'family') {
            $value = $request->query('patient_invitation_id');
            $id = filter_var($value, FILTER_VALIDATE_INT);
            abort_unless(is_int($id) && $id > 0, 404);
            $request->session()->put('patient_invitation_id', $id);
        }
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
