<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\Portal\OnboardingController;
use App\Http\Controllers\Portal\SectionController;
use App\Http\Controllers\Portal\ServiceIndexController;
use App\Http\Controllers\Portal\TelegramAuthenticationController;
use App\Http\Middleware\RequireClientPortalSession;
use App\Http\Middleware\ResolveClientPortalSession;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');
Route::middleware(ResolveOrganization::class)->group(function (): void {
    Route::post('/portal/telegram/auth', TelegramAuthenticationController::class)
        ->middleware('throttle:20,1')
        ->name('portal.telegram.auth');

    Route::middleware(ResolveClientPortalSession::class)->group(function (): void {
        Route::get('/', ServiceIndexController::class)->name('portal.services.index');
        Route::get('/portal/sections/{section}', SectionController::class)->name('portal.section');

        Route::middleware(RequireClientPortalSession::class)->group(function (): void {
            Route::get('/portal/onboarding', [OnboardingController::class, 'show'])->name('portal.onboarding');
            Route::post('/portal/onboarding/{stage}', [OnboardingController::class, 'update'])
                ->name('portal.onboarding.update');
        });
    });
});
