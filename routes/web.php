<?php

use App\Http\Controllers\AdminB2bSalesCallHostLaunchController;
use App\Http\Controllers\AdminCompanionController;
use App\Http\Controllers\AdminFinanceReceiptController;
use App\Http\Controllers\AdminKnowledgeRevisionDownloadController;
use App\Http\Controllers\AdminMedicalAttachmentController;
use App\Http\Controllers\CompanionExportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Portal\AttributionController;
use App\Http\Controllers\Portal\AvailabilityController;
use App\Http\Controllers\Portal\B2bController;
use App\Http\Controllers\Portal\BookingController;
use App\Http\Controllers\Portal\CompanionController;
use App\Http\Controllers\Portal\EmailAuthenticationController;
use App\Http\Controllers\Portal\FeedbackController;
use App\Http\Controllers\Portal\FinanceController;
use App\Http\Controllers\Portal\FinanceReceiptController;
use App\Http\Controllers\Portal\HomeController;
use App\Http\Controllers\Portal\LocaleController;
use App\Http\Controllers\Portal\OnboardingController;
use App\Http\Controllers\Portal\ProfileController;
use App\Http\Controllers\Portal\ReferralController;
use App\Http\Controllers\Portal\ReferralRedirectController;
use App\Http\Controllers\Portal\SectionController;
use App\Http\Controllers\Portal\ServiceIndexController;
use App\Http\Controllers\Portal\SurveyController;
use App\Http\Controllers\Portal\TelegramAuthenticationController;
use App\Http\Controllers\Portal\TelegramLinkController;
use App\Http\Controllers\Portal\TelegramMiniAppLaunchController;
use App\Http\Controllers\Portal\TelegramWebAuthenticationController;
use App\Http\Middleware\CapturePortalAttribution;
use App\Http\Middleware\RequireClientPortalSession;
use App\Http\Middleware\ResolveClientPortalSession;
use App\Http\Middleware\ResolveOrganization;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');
Route::middleware(ResolveOrganization::class)->group(function (): void {
    Route::get('/admin/finance/receipts/{receiptId}', AdminFinanceReceiptController::class)
        ->middleware('auth')
        ->whereNumber('receiptId')
        ->name('admin.finance.receipt');
    Route::get('/admin/attachments/{uuid}', AdminMedicalAttachmentController::class)
        ->middleware('auth')
        ->name('admin.attachments.download');
    Route::get('/knowledge/revisions/{knowledgeSourceId}/{knowledgeRevisionId}/download', AdminKnowledgeRevisionDownloadController::class)
        ->middleware(Authenticate::class)
        ->whereNumber(['knowledgeSourceId', 'knowledgeRevisionId'])
        ->name('admin.knowledge.revision.download');
    Route::post('/admin/clients/{client}/companion/reply', [AdminCompanionController::class, 'reply'])
        ->middleware('auth')->whereNumber('client')->name('admin.clients.companion.reply');
    Route::post('/admin/clients/{client}/companion/resolve', [AdminCompanionController::class, 'resolve'])
        ->middleware('auth')->whereNumber('client')->name('admin.clients.companion.resolve');
    Route::post('/admin/clients/{client}/companion/resume', [AdminCompanionController::class, 'resume'])
        ->middleware('auth')->whereNumber('client')->name('admin.clients.companion.resume');
    Route::post('/admin/clients/{client}/companion/reset', [AdminCompanionController::class, 'reset'])
        ->middleware('auth')->whereNumber('client')->name('admin.clients.companion.reset');
    Route::get('/admin/b2b-sales-calls/{salesCallId}/host-launch', AdminB2bSalesCallHostLaunchController::class)
        ->middleware('auth')->whereNumber('salesCallId')->name('admin.b2b.sales-call.host-launch');
    Route::get('/admin/clients/{client}/companion/export', [CompanionExportController::class, 'history'])
        ->middleware('auth')->whereNumber('client')->name('admin.clients.companion.export');
    Route::get('/admin/clients/{client}/companion/metadata-export', [CompanionExportController::class, 'metadata'])
        ->middleware('auth')->whereNumber('client')->name('admin.clients.companion.metadata-export');
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

    Route::middleware([ResolveClientPortalSession::class, CapturePortalAttribution::class])->group(function (): void {
        Route::get('/portal/telegram/launch/{entry}', TelegramMiniAppLaunchController::class)
            ->where('entry', '[A-Za-z0-9_-]+')
            ->name('portal.telegram.launch');
        Route::post('/portal/locale', LocaleController::class)->name('portal.locale.update');
        Route::get('/r/{referralCode}', ReferralRedirectController::class)
            ->where('referralCode', '[A-Za-z0-9_-]{16,128}')
            ->name('portal.referral');
        Route::get('/', HomeController::class)->name('portal.home');
        Route::get('/portal/services', ServiceIndexController::class)->name('portal.services.index');
        Route::get('/portal/b2b', B2bController::class)->name('portal.b2b');
        Route::get('/portal/sections/{section}', SectionController::class)->name('portal.section');

        Route::middleware(RequireClientPortalSession::class)->group(function (): void {
            Route::get('/portal/availability', AvailabilityController::class)->name('portal.availability');
            Route::get('/portal/bookings/create', [BookingController::class, 'create'])->name('portal.bookings.create');
            Route::post('/portal/bookings', [BookingController::class, 'store'])->name('portal.bookings.store');
            Route::get('/portal/bookings', [BookingController::class, 'index'])->name('portal.bookings.index');
            Route::get('/portal/finance', [FinanceController::class, 'index'])->name('portal.finance.index');
            Route::get('/portal/referrals', ReferralController::class)->name('portal.referrals');
            Route::get('/portal/feedback', [FeedbackController::class, 'index'])->name('portal.feedback');
            Route::post('/portal/feedback', [FeedbackController::class, 'store'])->name('portal.feedback.store');
            Route::get('/portal/attribution', [AttributionController::class, 'show'])->name('portal.attribution');
            Route::post('/portal/attribution', [AttributionController::class, 'update'])->name('portal.attribution.update');
            Route::get('/portal/surveys', [SurveyController::class, 'index'])->name('portal.surveys.index');
            Route::get('/portal/companion', [CompanionController::class, 'index'])->name('portal.companion');
            Route::post('/portal/companion/messages', [CompanionController::class, 'send'])
                ->middleware('throttle:portal-companion-send')
                ->name('portal.companion.send');
            Route::post('/portal/companion/feedback/{messageId}', [CompanionController::class, 'feedback'])
                ->whereNumber('messageId')
                ->name('portal.companion.feedback');
            Route::post('/portal/companion/reset', [CompanionController::class, 'reset'])
                ->name('portal.companion.reset');
            Route::post('/portal/surveys/{definitionId}/start', [SurveyController::class, 'start'])->whereNumber('definitionId')->name('portal.surveys.start');
            Route::get('/portal/survey-attempts/{attemptId}', [SurveyController::class, 'show'])->whereNumber('attemptId')->name('portal.surveys.show');
            Route::post('/portal/survey-attempts/{attemptId}/save', [SurveyController::class, 'save'])->whereNumber('attemptId')->name('portal.surveys.save');
            Route::post('/portal/survey-attempts/{attemptId}/complete', [SurveyController::class, 'complete'])->whereNumber('attemptId')->name('portal.surveys.complete');
            Route::get('/portal/survey-reports/{reportId}', [SurveyController::class, 'report'])->whereNumber('reportId')->name('portal.surveys.report');
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
            Route::post('/portal/profile/b2b-answer', [ProfileController::class, 'b2bAnswer'])
                ->name('portal.profile.b2b-answer');
            Route::post('/portal/b2b/leads', [B2bController::class, 'submit'])
                ->name('portal.b2b.submit');
            Route::get('/portal/onboarding', [OnboardingController::class, 'show'])->name('portal.onboarding');
            Route::post('/portal/onboarding/{stage}', [OnboardingController::class, 'update'])
                ->name('portal.onboarding.update');
        });
    });
});
