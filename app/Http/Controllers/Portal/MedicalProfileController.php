<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\ClientPortal\Application\GetClientMedicalProfile;
use App\Modules\ClientPortal\Application\UpdateClientMedicalProfile;
use App\Modules\Scheduling\Application\ListClientBookings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class MedicalProfileController extends Controller
{
    public function show(Request $request, GetClientMedicalProfile $profile, ListClientBookings $bookings): Response
    {
        Inertia::encryptHistory();
        $bookingId = $request->query('booking_id');
        if ($bookingId !== null) {
            abort_unless(is_scalar($bookingId) && ctype_digit((string) $bookingId) && $bookings->find((int) $bookingId) !== null, 404);
        }

        return Inertia::render('Portal/MedicalProfile', [
            'profile' => $profile->handle(),
            'saved' => (bool) session()->pull('medical_profile_saved', false),
            'editing' => $request->boolean('edit'),
            'returnUrl' => $bookingId === null ? route('portal.more') : route('portal.bookings.show', ['bookingId' => (int) $bookingId]),
            'urls' => ['update' => route('portal.medical-profile.update', ['booking_id' => $bookingId])],
        ]);
    }

    public function update(Request $request, UpdateClientMedicalProfile $update, ListClientBookings $bookings): RedirectResponse
    {
        $bookingId = $request->query('booking_id');
        if ($bookingId !== null) {
            abort_unless(is_scalar($bookingId) && ctype_digit((string) $bookingId) && $bookings->find((int) $bookingId) !== null, 404);
        }
        $update->handle($request->only(['expected_snapshot', 'complaints', 'goals', 'chronic_conditions', 'operations', 'injuries', 'medicines', 'supplements', 'answers']));
        if ($bookingId !== null) {
            return to_route('portal.bookings.show', ['bookingId' => (int) $bookingId])->with('success', __('Медицинские данные подтверждены'));
        }

        return to_route('portal.medical-profile')->with('medical_profile_saved', true);
    }
}
