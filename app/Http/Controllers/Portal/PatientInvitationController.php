<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\PortalClientMessages;
use App\Modules\Identity\Application\AcceptPatientInvitation;
use App\Modules\Identity\Application\PatientProfileProjection;
use App\Modules\Identity\Domain\Models\PatientInvitation;
use App\Modules\Identity\Domain\Models\PatientProfile;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class PatientInvitationController extends Controller
{
    public function show(
        Request $request,
        ClientPortalContext $context,
        PatientProfileProjection $projection,
        PortalClientMessages $messages,
    ): Response|RedirectResponse {
        $id = $request->session()->get('patient_invitation_id');
        abort_unless(is_int($id), 404);
        $actor = $context->client();
        $invitation = PatientInvitation::query()->where('organization_id', $actor->organization_id)->whereKey($id)->firstOrFail();
        if ((int) $invitation->intended_client_id !== (int) $actor->getKey()) {
            $this->logInvitationFailure($context, $id, 'patient.invitation.open', 'invitation_wrong_account', 'denied');

            return to_route('portal.family')->with('error', $messages->message('family_invitation_wrong_account'));
        }
        if ($invitation->revoked_at !== null) {
            $this->logInvitationFailure($context, $id, 'patient.invitation.open', 'invitation_revoked', 'denied');

            return to_route('portal.family')->with('error', $messages->message('family_invitation_revoked'));
        }
        if ($invitation->accepted_at === null && CarbonImmutable::parse((string) $invitation->expires_at)->isPast()) {
            $this->logInvitationFailure($context, $id, 'patient.invitation.open', 'invitation_expired', 'denied');

            return to_route('portal.family')->with('error', $messages->message('family_invitation_expired'));
        }
        $patient = PatientProfile::query()->where('organization_id', $context->client()->organization_id)->whereKey($invitation->patient_profile_id)->with('clinicalClient')->firstOrFail();
        Inertia::encryptHistory();

        return Inertia::render('Portal/PatientInvitation', [
            'patient' => $projection->handle($patient, $context->client()), 'accepted' => $invitation->accepted_at !== null,
            'urls' => ['accept' => route('portal.family.invitation.accept'), 'profile' => route('portal.profile', ['return_to' => 'patient_invitation']), 'family' => route('portal.family')],
        ]);
    }

    public function accept(
        Request $request,
        ClientPortalContext $context,
        AcceptPatientInvitation $accept,
        PortalClientMessages $messages,
    ): RedirectResponse {
        $id = $request->session()->get('patient_invitation_id');
        abort_unless(is_int($id), 404);
        $actor = $context->client();
        try {
            $invitation = PatientInvitation::query()->where('organization_id', $actor->organization_id)->whereKey($id)->firstOrFail();
            if ((int) $invitation->intended_client_id !== (int) $actor->getKey()) {
                throw new AuthorizationException;
            }
            if ($invitation->revoked_at !== null) {
                throw ValidationException::withMessages(['invitation' => $messages->message('family_invitation_revoked')]);
            }
            if ($invitation->accepted_at === null && CarbonImmutable::parse((string) $invitation->expires_at)->isPast()) {
                throw ValidationException::withMessages(['invitation' => $messages->message('family_invitation_expired')]);
            }

            $accept->handle($actor, $id, $request->boolean('confirm_identity'));
        } catch (ValidationException $exception) {
            $errorCode = match (array_key_first($exception->errors())) {
                'confirm_identity' => 'identity_confirmation_missing',
                'invitation' => 'invitation_validation_rejected',
                default => 'invitation_validation_failed',
            };
            $this->logInvitationFailure($context, $id, 'patient.invitation.accept', $errorCode, 'denied', $exception::class);
            throw $exception;
        } catch (AuthorizationException $exception) {
            $this->logInvitationFailure($context, $id, 'patient.invitation.accept', 'invitation_authorization_denied', 'denied', $exception::class);
            throw $exception;
        } catch (Throwable $exception) {
            $this->logInvitationFailure($context, $id, 'patient.invitation.accept', 'invitation_accept_failed', 'failed', $exception::class);
            throw $exception;
        }

        return to_route('portal.family.invitation')->with('success', $messages->message('family_invitation_accepted'));
    }

    private function logInvitationFailure(
        ClientPortalContext $context,
        int $invitationId,
        string $operation,
        string $errorCode,
        string $outcome,
        ?string $errorClass = null,
    ): void {
        $actor = $context->client();

        Log::warning('portal.family.invitation.failed', [
            'operation' => $operation,
            'organization_id' => $actor->organization_id,
            'actor_type' => 'client',
            'actor_client_id' => $actor->getKey(),
            'entity_type' => 'patient_invitation',
            'entity_id' => $invitationId,
            'safe_error_code' => $errorCode,
            'error_class' => $errorClass,
            'outcome' => $outcome,
            'occurred_at_utc' => now()->utc()->toIso8601String(),
        ]);
    }
}
