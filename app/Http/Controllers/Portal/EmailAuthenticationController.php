<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestClientEmailCodeRequest;
use App\Http\Requests\VerifyClientEmailCodeRequest;
use App\Modules\Identity\Application\AuthenticateClientWithEmailVerificationCode;
use App\Modules\Identity\Application\InvalidEmailAuthenticationCode;
use App\Modules\Identity\Application\RequestClientEmailVerificationCode;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class EmailAuthenticationController extends Controller
{
    public function requestCode(
        RequestClientEmailCodeRequest $request,
        RequestClientEmailVerificationCode $requestCode,
    ): RedirectResponse {
        $requestCode->handle($request->validated('email'));

        return to_route('portal.services.index')->with('email_code_sent', true);
    }

    public function verifyCode(
        VerifyClientEmailCodeRequest $request,
        AuthenticateClientWithEmailVerificationCode $authenticate,
    ): RedirectResponse {
        try {
            $client = $authenticate->handle(
                email: $request->validated('email'),
                code: $request->validated('code'),
            );
        } catch (InvalidEmailAuthenticationCode) {
            $request->session()->flash('email_code_sent', true);

            throw ValidationException::withMessages([
                'code' => 'The verification code is invalid or expired.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('client_portal.client_id', $client->getKey());

        return to_route('portal.onboarding');
    }
}
