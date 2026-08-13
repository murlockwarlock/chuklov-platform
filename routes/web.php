<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Portal\AvailabilityController;
use App\Http\Controllers\Portal\BookingController;
use App\Http\Controllers\Portal\EmailAuthenticationController;
use App\Http\Controllers\Portal\OnboardingController;
use App\Http\Controllers\Portal\SectionController;
use App\Http\Controllers\Portal\ServiceIndexController;
use App\Http\Controllers\Portal\TelegramAuthenticationController;
use App\Http\Controllers\Portal\TelegramLinkController;
use App\Http\Middleware\RequireClientPortalSession;
use App\Http\Middleware\ResolveClientPortalSession;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');
Route::middleware(ResolveOrganization::class)->group(function (): void {
    Route::post('/portal/telegram/auth', TelegramAuthenticationController::class)
        ->middleware('throttle:portal-telegram-auth')
        ->name('portal.telegram.auth');
    Route::post('/portal/auth/email/request', [EmailAuthenticationController::class, 'requestCode'])
        ->middleware('throttle:portal-email-request')
        ->name('portal.email.request');
    Route::post('/portal/auth/email/verify', [EmailAuthenticationController::class, 'verifyCode'])
        ->middleware('throttle:portal-email-verify')
        ->name('portal.email.verify');

    Route::middleware(ResolveClientPortalSession::class)->group(function (): void {
        Route::get('/', ServiceIndexController::class)->name('portal.services.index');
        Route::get('/portal/sections/{section}', SectionController::class)->name('portal.section');

        Route::middleware(RequireClientPortalSession::class)->group(function (): void {
            Route::get('/portal/availability', AvailabilityController::class)->name('portal.availability');
            Route::get('/portal/bookings/create', [BookingController::class, 'create'])->name('portal.bookings.create');
            Route::post('/portal/bookings', [BookingController::class, 'store'])->name('portal.bookings.store');
            Route::get('/portal/bookings', [BookingController::class, 'index'])->name('portal.bookings.index');
            Route::get('/portal/bookings/{bookingId}', [BookingController::class, 'show'])
                ->whereNumber('bookingId')
                ->name('portal.bookings.show');
            Route::post('/portal/bookings/{bookingId}/cancel', [BookingController::class, 'cancel'])
                ->whereNumber('bookingId')
                ->name('portal.bookings.cancel');
            Route::post('/portal/bookings/{bookingId}/reschedule', [BookingController::class, 'reschedule'])
                ->whereNumber('bookingId')
                ->name('portal.bookings.reschedule');
            Route::post('/portal/preferences/timezone', [BookingController::class, 'updateTimezone'])
                ->name('portal.preferences.timezone');
            Route::post('/portal/channels/telegram/link', TelegramLinkController::class)
                ->name('portal.telegram.link');
            Route::get('/portal/onboarding', [OnboardingController::class, 'show'])->name('portal.onboarding');
            Route::post('/portal/onboarding/{stage}', [OnboardingController::class, 'update'])
                ->name('portal.onboarding.update');
        });
    });
});
