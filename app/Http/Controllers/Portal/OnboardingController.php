<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveClientOnboardingStepRequest;
use App\Modules\ClientPortal\Application\GetClientOnboarding;
use App\Modules\ClientPortal\Application\SaveClientOnboardingStep;
use App\Modules\ClientPortal\Domain\Enums\ClientOnboardingStage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class OnboardingController extends Controller
{
    public function show(GetClientOnboarding $onboarding): Response
    {
        return Inertia::render('Portal/Onboarding', $onboarding->handle());
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
        unset($validated['confirmed_fields']);

        $saveStep->handle($onboardingStage, $validated, $confirmedFields);

        return to_route('portal.onboarding');
    }
}
