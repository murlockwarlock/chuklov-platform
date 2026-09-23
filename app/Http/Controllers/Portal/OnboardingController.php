<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveClientOnboardingStepRequest;
use App\Modules\ClientPortal\Application\PortalClientMessages;
use App\Modules\ClientPortal\Application\SaveClientOnboardingStep;
use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse;

class OnboardingController extends Controller
{
    public function show(): RedirectResponse
    {
        return to_route('portal.profile');
    }

    public function update(
        SaveClientOnboardingStepRequest $request,
        string $stage,
        SaveClientOnboardingStep $saveStep,
        PortalClientMessages $messages,
    ): RedirectResponse {
        $onboardingStage = ClientOnboardingStage::tryFrom($stage);
        abort_unless($onboardingStage instanceof ClientOnboardingStage, 404);

        $validated = $request->validated();
        $confirmedFields = $validated['confirmed_fields'] ?? [];
        $consents = $validated['consents'] ?? [];
        unset($validated['confirmed_fields']);
        unset($validated['consents']);

        try {
            $saveStep->handle($onboardingStage, $validated, $confirmedFields, $consents);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages($messages->validationException('onboarding', $exception));
        }

        return to_route('portal.onboarding');
    }
}
