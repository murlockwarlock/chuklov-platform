<?php

use App\Http\Controllers\AdminFinanceReceiptController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Portal\AvailabilityController;
use App\Http\Controllers\Portal\BookingController;
use App\Http\Controllers\Portal\EmailAuthenticationController;
use App\Http\Controllers\Portal\FinanceController;
use App\Http\Controllers\Portal\FinanceReceiptController;
use App\Http\Controllers\Portal\HomeController;
use App\Http\Controllers\Portal\LocaleController;
use App\Http\Controllers\Portal\OnboardingController;
use App\Http\Controllers\Portal\ProfileController;
use App\Http\Controllers\Portal\SectionController;
use App\Http\Controllers\Portal\ServiceIndexController;
use App\Http\Controllers\Portal\TelegramAuthenticationController;
use App\Http\Controllers\Portal\TelegramLinkController;
use App\Http\Controllers\Portal\TelegramWebAuthenticationController;
use App\Http\Middleware\RequireClientPortalSession;
use App\Http\Middleware\ResolveClientPortalSession;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');
Route::middleware(ResolveOrganization::class)->group(function (): void {
    Route::get('/admin/finance/receipts/{receiptId}', AdminFinanceReceiptController::class)
        ->middleware('auth')
        ->whereNumber('receiptId')
        ->name('admin.finance.receipt');
    Route::post('/portal/telegram/auth', TelegramAuthenticationController::class)
        ->middleware('throttle:portal-telegram-auth')
        ->name('portal.telegram.auth');
    Route::post('/portal/auth/email/request', [EmailAuthenticationController::class, 'requestCode'])
        ->middleware('throttle:portal-email-request')
        ->name('portal.email.request');
    Route::post('/portal/auth/email/verify', [EmailAuthenticationController::class, 'verifyCode'])
        ->middleware('throttle:portal-email-verify')
        ->name('portal.email.verify');
    Route::post('/portal/auth/telegram/request', [TelegramWebAuthenticationController::class, 'request'])
        ->middleware('throttle:portal-telegram-web-request')
        ->name('portal.telegram.web.request');
    Route::get('/portal/auth/telegram/status', [TelegramWebAuthenticationController::class, 'status'])
        ->middleware('throttle:portal-telegram-web-status')
        ->name('portal.telegram.web.status');

    Route::middleware(ResolveClientPortalSession::class)->group(function (): void {
        Route::post('/portal/locale', LocaleController::class)->name('portal.locale.update');
        Route::get('/', HomeController::class)->name('portal.home');
        Route::get('/portal/services', ServiceIndexController::class)->name('portal.services.index');
        Route::get('/portal/sections/{section}', SectionController::class)->name('portal.section');

        Route::middleware(RequireClientPortalSession::class)->group(function (): void {
            Route::get('/portal/availability', AvailabilityController::class)->name('portal.availability');
            Route::get('/portal/bookings/create', [BookingController::class, 'create'])->name('portal.bookings.create');
            Route::post('/portal/bookings', [BookingController::class, 'store'])->name('portal.bookings.store');
            Route::get('/portal/bookings', [BookingController::class, 'index'])->name('portal.bookings.index');
            Route::get('/portal/finance', [FinanceController::class, 'index'])->name('portal.finance.index');
            Route::get('/portal/finance/receipts/{receiptId}', FinanceReceiptController::class)
                ->whereNumber('receiptId')
                ->name('portal.finance.receipt');
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
            Route::get('/portal/profile', [ProfileController::class, 'show'])->name('portal.profile');
            Route::post('/portal/profile', [ProfileController::class, 'update'])->name('portal.profile.update');
            Route::post('/portal/profile/consents', [ProfileController::class, 'consents'])
                ->name('portal.profile.consents');
            Route::get('/portal/onboarding', [OnboardingController::class, 'show'])->name('portal.onboarding');
            Route::post('/portal/onboarding/{stage}', [OnboardingController::class, 'update'])
                ->name('portal.onboarding.update');
        });
    });
});
