<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\GetClientMedicalProfile;
use App\Modules\ClientPortal\Application\ListClientMedicalMaterials;
use App\Modules\ClientPortal\Application\UpdateClientMedicalProfile;
use App\Modules\Scheduling\Application\ListClientBookings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MedicalProfileController extends Controller
{
    public function show(Request $request, GetClientMedicalProfile $profile, ListClientBookings $bookings, ListClientMedicalMaterials $materials): Response
    {
        Inertia::encryptHistory();
        $request->validate(['return_to' => ['sometimes', 'string', 'in:bookings']]);
        $bookingId = $request->query('booking_id');
        if ($bookingId !== null) {
            abort_unless(is_scalar($bookingId) && ctype_digit((string) $bookingId) && $bookings->find((int) $bookingId) !== null, 404);
        }

        return Inertia::render('Portal/MedicalProfile', [
            'profile' => $profile->handle(),
            'materials' => fn (): array => $materials->handle(),
            'saved' => (bool) session()->pull('medical_profile_saved', false),
            'editing' => $request->boolean('edit'),
            'returnUrl' => $this->returnUrl($request, $bookingId),
            'returnLabelKey' => $bookingId === null ? 'more.title' : ($request->query('return_to') === 'bookings' ? 'bookings.title' : 'medical.backToBooking'),
            'urls' => [
                'update' => route('portal.medical-profile.update', [
                    'booking_id' => $bookingId,
                    ...($request->query('return_to') === 'bookings' ? ['return_to' => 'bookings'] : []),
                ]),
                'upload' => route('portal.medical-attachments.upload'),
            ],
        ]);
    }

    public function update(Request $request, UpdateClientMedicalProfile $update, ListClientBookings $bookings): RedirectResponse
    {
        $request->validate(['return_to' => ['sometimes', 'string', 'in:bookings']]);
        $bookingId = $request->query('booking_id');
        if ($bookingId !== null) {
            abort_unless(is_scalar($bookingId) && ctype_digit((string) $bookingId) && $bookings->find((int) $bookingId) !== null, 404);
        }
        $update->handle($request->only(['expected_snapshot', 'complaints', 'goals', 'chronic_conditions', 'operations', 'injuries', 'medicines', 'supplements', 'answers']));
        if ($bookingId !== null) {
            return redirect($this->returnUrl($request, $bookingId))->with('success', __('Медицинские данные подтверждены'));
        }

        return to_route('portal.medical-profile')->with('medical_profile_saved', true);
    }

    private function returnUrl(Request $request, mixed $bookingId): string
    {
        if ($bookingId === null) {
            return route('portal.more');
        }

        return $request->query('return_to') === 'bookings'
            ? route('portal.bookings.index')
            : route('portal.bookings.show', ['bookingId' => (int) $bookingId]);
    }
}
