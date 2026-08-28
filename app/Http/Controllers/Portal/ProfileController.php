<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordPortalClientConsentsRequest;
use App\Http\Requests\UpdateClientB2bAnswerRequest;
use App\Http\Requests\UpdateClientProfileRequest;
use App\Modules\Broadcasts\Application\SetClientB2bSpecialistAnswer;
use App\Modules\Broadcasts\Domain\Enums\B2bSpecialistAnswer;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\GetClientProfile;
use App\Modules\Identity\Application\RecordPortalClientConsents;
use App\Modules\Identity\Application\UpdateClientProfileFromPortal;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function show(
        GetClientProfile $profile,
    ): Response {
        $data = $profile->handle();
        $data['telegram']['linkUrl'] = session()->pull('telegram_link_url');
        $data['telegram']['linkError'] = (bool) session()->pull('telegram_link_error', false);

        return Inertia::render('Portal/Profile', [
            ...$data,
            'saved' => (bool) session()->pull('profile_saved', false),
            'consentsSaved' => (bool) session()->pull('consents_saved', false),
        ]);
    }

    public function update(
        UpdateClientProfileRequest $request,
        ClientPortalContext $clientContext,
        UpdateClientProfileFromPortal $updateProfile,
    ): RedirectResponse {
        $validated = $request->validated();
        $updateProfile->handle($clientContext->client(), $validated, array_keys($validated));

        return to_route('portal.profile')->with('profile_saved', true);
    }

    public function consents(
        RecordPortalClientConsentsRequest $request,
        ClientPortalContext $clientContext,
        RecordPortalClientConsents $recordConsents,
    ): RedirectResponse {
        $recordConsents->handle($clientContext->client(), $request->validated('consents'));

        return to_route('portal.profile')->with('consents_saved', true);
    }

    public function b2bAnswer(
        UpdateClientB2bAnswerRequest $request,
        ClientPortalContext $clientContext,
        SetClientB2bSpecialistAnswer $setAnswer,
    ): RedirectResponse {
        $setAnswer->handle(
            actor: $clientContext->client(),
            client: $clientContext->client(),
            answer: B2bSpecialistAnswer::from($request->validated('b2b_specialist_answer')),
            source: 'portal',
        );

        $route = $request->validated('return_to') === 'b2b'
            ? 'portal.b2b'
            : 'portal.profile';

        return to_route($route)->with('b2b_answer_saved', true);
    }
}
