<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveClientOnboardingStepRequest;
use App\Modules\ClientPortal\Application\SaveClientOnboardingStep;
use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
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
    ): RedirectResponse {
        $onboardingStage = ClientOnboardingStage::tryFrom($stage);
        abort_unless($onboardingStage instanceof ClientOnboardingStage, 404);

        $validated = $request->validated();
        $confirmedFields = $validated['confirmed_fields'] ?? [];
        $consents = $validated['consents'] ?? [];
        unset($validated['confirmed_fields']);
        unset($validated['consents']);

        $saveStep->handle($onboardingStage, $validated, $confirmedFields, $consents);

        return to_route('portal.onboarding');
    }
}
