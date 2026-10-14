<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePatientProfileRequest;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\ClientPortal\Application\ListPatientProfiles;
use App\Modules\Identity\Application\CreatePatientProfile;
use App\Modules\Identity\Application\IssuePatientInvitation;
use App\Modules\Identity\Application\RevokePatientRelationship;
use App\Modules\Identity\Application\UpdatePatientProfile;
use App\Modules\Identity\Domain\Models\PatientProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

    public function store(CreatePatientProfileRequest $request, ClientPortalContext $context, CreatePatientProfile $create): RedirectResponse
    {
        $data = $request->validated();
        $patient = $create->handle($context->client(), $data, $data['creation_key']);
        if (($data['return_to'] ?? null) === 'booking') {
            return to_route('portal.bookings.create', [
                'patient_profile_id' => $patient->getKey(), 'service_id' => $data['service_id'] ?? null,
                'specialist_id' => $data['specialist_id'] ?? null, 'format' => $data['format'] ?? 'office',
            ]);
        }

        return to_route('portal.family');
    }

    public function invite(Request $request, int $patientId, ClientPortalContext $context, IssuePatientInvitation $issue): JsonResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid']]);
        $patient = PatientProfile::query()->where('organization_id', $context->client()->organization_id)->whereKey($patientId)->firstOrFail();
        $invitation = $issue->handle($context->client(), $patient, $data['request_key']);
        $username = config('portal.telegram.bot_username');
        abort_unless(is_string($username) && preg_match('/^[A-Za-z0-9_]{5,32}$/D', $username) === 1, 503);

        return response()->json(['url' => 'https://t.me/'.$username.'?start=family_'.$invitation->encrypted_token,
            'expiresAt' => $invitation->expires_at->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function revoke(int $patientId, ClientPortalContext $context, RevokePatientRelationship $revoke): RedirectResponse
    {
        $patient = PatientProfile::query()->where('organization_id', $context->client()->organization_id)->whereKey($patientId)->firstOrFail();
        $revoke->handle($context->client(), $patient);

        return to_route('portal.family');
    }

    public function update(Request $request, int $patientId, ClientPortalContext $context, UpdatePatientProfile $update): RedirectResponse
    {
        $patient = PatientProfile::query()->where('organization_id', $context->client()->organization_id)->whereKey($patientId)->firstOrFail();
        $update->handle($context->client(), $patient, $request->only(['first_name', 'last_name', 'middle_name', 'gender', 'birth_date', 'relationship']));

        return to_route('portal.family');
    }
}
