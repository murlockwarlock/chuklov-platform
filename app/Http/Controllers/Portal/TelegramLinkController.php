<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\InitiateTelegramClientLink;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class TelegramLinkController extends Controller
{
    public function __invoke(Request $request, InitiateTelegramClientLink $initiate): RedirectResponse
    {
        try {
            $url = $initiate->handle();
        } catch (LogicException) {
            return to_route('portal.profile')->with('telegram_link_error', true);
        }

        return to_route('portal.profile')->with('telegram_link_url', $url);
    }
}
