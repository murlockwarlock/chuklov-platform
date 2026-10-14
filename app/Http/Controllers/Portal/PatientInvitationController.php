<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Application\AcceptPatientInvitation;
use App\Modules\Identity\Application\PatientProfileProjection;
use App\Modules\Identity\Domain\Models\PatientInvitation;
use App\Modules\Identity\Domain\Models\PatientProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PatientInvitationController extends Controller
{
    public function show(Request $request, ClientPortalContext $context, PatientProfileProjection $projection): Response
    {
        $id = $request->session()->get('patient_invitation_id');
        abort_unless(is_int($id), 404);
        $invitation = PatientInvitation::query()->where('organization_id', $context->client()->organization_id)->whereKey($id)
            ->where('intended_client_id', $context->client()->getKey())->whereNull('revoked_at')->firstOrFail();
        abort_if($invitation->accepted_at === null && $invitation->expires_at->isPast(), 410);
        $patient = PatientProfile::query()->where('organization_id', $context->client()->organization_id)->whereKey($invitation->patient_profile_id)->with('clinicalClient')->firstOrFail();
        Inertia::encryptHistory();

        return Inertia::render('Portal/PatientInvitation', [
            'patient' => $projection->handle($patient, $context->client()), 'accepted' => $invitation->accepted_at !== null,
            'urls' => ['accept' => route('portal.family.invitation.accept'), 'profile' => route('portal.profile', ['return_to' => 'patient_invitation']), 'family' => route('portal.family')],
        ]);
    }

    public function accept(Request $request, ClientPortalContext $context, AcceptPatientInvitation $accept): RedirectResponse
    {
        $data = $request->validate(['confirm_identity' => ['required', 'accepted']]);
        $id = $request->session()->get('patient_invitation_id');
        abort_unless(is_int($id), 404);
        $accept->handle($context->client(), $id, (bool) $data['confirm_identity']);

        return to_route('portal.family.invitation');
    }
}
