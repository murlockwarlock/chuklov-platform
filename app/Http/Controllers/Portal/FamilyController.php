<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePatientProfileRequest;
use App\Http\Requests\UpdatePatientProfileRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\ListPatientProfiles;
use App\Modules\ClientPortal\Application\PortalClientMessages;
use App\Modules\Identity\Application\CreatePatientProfile;
use App\Modules\Identity\Application\IssuePatientInvitation;
use App\Modules\Identity\Application\PatientProfileAccess;
use App\Modules\Identity\Application\RevokePatientRelationship;
use App\Modules\Identity\Application\UpdatePatientProfile;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\PatientProfile;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class FamilyController extends Controller
{
    public function index(Request $request, ClientPortalContext $context, ListPatientProfiles $patients): Response
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        Inertia::encryptHistory();

        return Inertia::render('Portal/Family', [
            'patients' => $patients->page($context->client()),
            'urls' => ['create' => route('portal.family.store'), 'profile' => route('portal.profile')],
        ]);
    }

    public function store(
        CreatePatientProfileRequest $request,
        ClientPortalContext $context,
        CreatePatientProfile $create,
        PortalClientMessages $messages,
    ): RedirectResponse {
        $data = $request->validated();
        $patient = $create->handle($context->client(), $data, $data['creation_key']);
        if (($data['return_to'] ?? null) === 'booking') {
            return to_route('portal.bookings.create', [
                'patient_profile_id' => $patient->getKey(), 'service_id' => $data['service_id'] ?? null,
                'specialist_id' => $data['specialist_id'] ?? null, 'format' => $data['format'] ?? 'office',
            ])->with('success', $messages->message('family_created'));
        }

        return to_route('portal.family')->with('success', $messages->message('family_created'));
    }

    public function invite(
        Request $request,
        int $patientId,
        ClientPortalContext $context,
        IssuePatientInvitation $issue,
        PatientProfileAccess $access,
        PortalClientMessages $messages,
    ): JsonResponse {
        $data = $request->validate(['request_key' => ['required', 'uuid']], [
            'request_key.required' => $messages->message('family_form_retry'),
            'request_key.uuid' => $messages->message('family_form_retry'),
        ]);
        $patient = PatientProfile::query()->where('organization_id', $context->client()->organization_id)->whereKey($patientId)->firstOrFail();
        $actor = $context->client();
        try {
            $access->authorizeOwner($actor, $patient);
        } catch (AuthorizationException $exception) {
            $this->logInvitationFailure($actor, $patientId, 'patient_owner_authorization_denied', 'denied', $exception::class);
            throw $exception;
        } catch (Throwable $exception) {
            $this->logInvitationFailure($actor, $patientId, 'invitation_issue_failed', 'failed', $exception::class);
            throw $exception;
        }

        $username = config('portal.telegram.bot_username');
        if (! is_string($username) || preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username) !== 1) {
            $this->logInvitationFailure($actor, $patientId, 'telegram_bot_configuration_missing', 'failed');

            return response()->json([
                'message' => $messages->message('family_telegram_unavailable'),
                'safe_error_code' => 'telegram_bot_configuration_missing',
            ], 503)
                ->header('Cache-Control', 'private, no-store');
        }

        try {
            $invitation = $issue->handle($actor, $patient, $data['request_key']);
        } catch (ValidationException $exception) {
            $this->logInvitationFailure($actor, $patientId, 'invitation_validation_rejected', 'denied', $exception::class);
            throw $exception;
        } catch (AuthorizationException $exception) {
            $this->logInvitationFailure($actor, $patientId, 'patient_owner_authorization_denied', 'denied', $exception::class);
            throw $exception;
        } catch (Throwable $exception) {
            $this->logInvitationFailure($actor, $patientId, 'invitation_issue_failed', 'failed', $exception::class);
            throw $exception;
        }

        return response()->json(['url' => 'https://t.me/'.$username.'?start=family_'.$invitation->encrypted_token,
            'expiresAt' => CarbonImmutable::parse((string) $invitation->expires_at)->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function revoke(
        int $patientId,
        ClientPortalContext $context,
        RevokePatientRelationship $revoke,
        PortalClientMessages $messages,
    ): RedirectResponse {
        $patient = PatientProfile::query()->where('organization_id', $context->client()->organization_id)->whereKey($patientId)->firstOrFail();
        $revoke->handle($context->client(), $patient);

        return to_route('portal.family')->with('success', $messages->message('family_unlinked'));
    }

    public function update(
        UpdatePatientProfileRequest $request,
        int $patientId,
        ClientPortalContext $context,
        UpdatePatientProfile $update,
        PortalClientMessages $messages,
    ): RedirectResponse {
        $patient = PatientProfile::query()->where('organization_id', $context->client()->organization_id)->whereKey($patientId)->firstOrFail();
        $update->handle($context->client(), $patient, $request->validated());

        return to_route('portal.family')->with('success', $messages->message('family_updated'));
    }

    private function logInvitationFailure(
        Client $actor,
        int $patientId,
        string $errorCode,
        string $outcome,
        ?string $errorClass = null,
    ): void {
        Log::warning('portal.family.invitation.failed', [
            'operation' => 'patient.invitation.issue',
            'organization_id' => $actor->organization_id,
            'actor_type' => 'client',
            'actor_client_id' => $actor->getKey(),
            'entity_type' => 'patient_profile',
            'entity_id' => $patientId,
            'provider' => 'telegram',
            'safe_error_code' => $errorCode,
            'error_class' => $errorClass,
            'outcome' => $outcome,
            'occurred_at_utc' => now()->utc()->toIso8601String(),
        ]);
    }
}
