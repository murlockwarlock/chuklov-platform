<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestClientEmailCodeRequest;
use App\Http\Requests\VerifyClientEmailCodeRequest;
use App\Modules\ClientPortal\Application\ApplyClientPortalLocale;
use App\Modules\Identity\Application\AuthenticateClientWithEmailVerificationCode;
use App\Modules\Identity\Application\InvalidEmailAuthenticationCode;
use App\Modules\Identity\Application\RequestClientEmailVerificationCode;
use App\Modules\Identity\Domain\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class EmailAuthenticationController extends Controller
{
    public function requestCode(
        RequestClientEmailCodeRequest $request,
        RequestClientEmailVerificationCode $requestCode,
    ): RedirectResponse {
        $requestCode->handle($request->validated('email'));

        return to_route('portal.home')->with('email_code_sent', true);
    }

    public function verifyCode(
        VerifyClientEmailCodeRequest $request,
        AuthenticateClientWithEmailVerificationCode $authenticate,
        ApplyClientPortalLocale $applyLocale,
    ): RedirectResponse {
        try {
            $client = $authenticate->handle(
                email: $request->validated('email'),
                code: $request->validated('code'),
            );
        } catch (InvalidEmailAuthenticationCode) {
            $request->session()->flash('email_code_sent', true);

            throw ValidationException::withMessages([
                'code' => 'Код неверный или уже истёк.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('client_portal.client_id', $client->getKey());
        $this->applySessionLocale($request, $client, $applyLocale);

        return to_route('portal.home');
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
}
