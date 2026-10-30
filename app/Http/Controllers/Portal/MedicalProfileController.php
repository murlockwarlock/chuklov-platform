<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\GetClientMedicalProfile;
use App\Modules\ClientPortal\Application\ListClientMedicalMaterials;
use App\Modules\ClientPortal\Application\ResolvePortalMedicalPatient;
use App\Modules\ClientPortal\Application\UpdateClientMedicalProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MedicalProfileController extends Controller
{
    public function show(Request $request, GetClientMedicalProfile $profile, ResolvePortalMedicalPatient $patients, ListClientMedicalMaterials $materials): Response
    {
        Inertia::encryptHistory();
        $request->validate(['return_to' => ['sometimes', 'string', 'in:bookings'], 'patient_profile_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'booking_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $bookingId = $request->integer('booking_id') ?: null;
        $patientId = $patients->handle($request->integer('patient_profile_id') ?: null, $bookingId);

        return Inertia::render('Portal/MedicalProfile', [
            'profile' => $profile->handle($patientId),
            'patientId' => $patientId,
            'materials' => fn (): array => $materials->handle($patientId),
            'saved' => (bool) session()->pull('medical_profile_saved', false),
            'editing' => $request->boolean('edit'),
            'returnUrl' => $this->returnUrl($request, $bookingId),
            'returnLabelKey' => $bookingId === null ? ($patientId === null ? 'more.title' : 'family.title') : ($request->query('return_to') === 'bookings' ? 'bookings.title' : 'medical.backToBooking'),
            'urls' => [
                'update' => route('portal.medical-profile.update', [
                    'booking_id' => $bookingId,
                    'patient_profile_id' => $patientId,
                    ...($request->query('return_to') === 'bookings' ? ['return_to' => 'bookings'] : []),
                ]),
                'upload' => route('portal.medical-attachments.upload', ['patient_profile_id' => $patientId]),
            ],
        ]);
    }

    public function update(Request $request, UpdateClientMedicalProfile $update, ResolvePortalMedicalPatient $patients): RedirectResponse
    {
        $request->validate(['return_to' => ['sometimes', 'string', 'in:bookings'], 'patient_profile_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'booking_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $bookingId = $request->integer('booking_id') ?: null;
        $patientId = $patients->handle($request->integer('patient_profile_id') ?: null, $bookingId);
        $update->handle($request->only(['expected_snapshot', 'complaints', 'goals', 'chronic_conditions', 'medicines', 'supplements', 'answers', 'pains', 'history_entries']), $patientId);
        if ($bookingId !== null) {
            return redirect($this->returnUrl($request, $bookingId))->with('success', __('Медицинские данные подтверждены'));
        }

        return to_route('portal.medical-profile', ['patient_profile_id' => $patientId])->with('medical_profile_saved', true);
    }

    private function returnUrl(Request $request, mixed $bookingId): string
    {
        if ($bookingId === null) {
            return $request->integer('patient_profile_id') > 0 ? route('portal.family') : route('portal.more');
        }

        return $request->query('return_to') === 'bookings'
            ? route('portal.bookings.index')
            : route('portal.bookings.show', ['bookingId' => (int) $bookingId]);
    }
}
