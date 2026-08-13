<?php

namespace App\Providers;

use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Infrastructure\Telegram\TelegramNotificationChannel;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Identity\Domain\Contracts\EmailVerificationCodeSender;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Identity\Infrastructure\Mail\LaravelEmailVerificationCodeSender;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Modules\Scenarios\Application\BookingStatusConditionEvaluator;
use App\Modules\Scenarios\Application\ClientLanguageConditionEvaluator;
use App\Modules\Scenarios\Application\ConditionEvaluatorRegistry;
use App\Modules\Scenarios\Application\OrganizationScenarioRecipientResolver;
use App\Modules\Scenarios\Application\ScenarioTemplateRenderer;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateRenderer;
use App\Modules\Scenarios\Domain\Contracts\ScenarioRecipientResolver;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Policies\AuditEventPolicy;
use App\Policies\BookingPolicy;
use App\Policies\ClientChannelIdentityPolicy;
use App\Policies\ClientConsentPolicy;
use App\Policies\ClientPolicy;
use App\Policies\ContentSectionPolicy;
use App\Policies\OrganizationCredentialPolicy;
use App\Policies\OrganizationFeatureFlagPolicy;
use App\Policies\OrganizationSettingPolicy;
use App\Policies\ScheduleExceptionPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SpecialistPolicy;
use App\Policies\SpecialistServiceAssignmentPolicy;
use App\Policies\UnavailablePeriodPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OrganizationContext::class);
        $this->app->scoped(ClientPortalContext::class);
        $this->app->bind(EmailVerificationCodeSender::class, LaravelEmailVerificationCodeSender::class);
        $this->app->singleton(
            NotificationChannelRegistry::class,
            fn (Application $app): NotificationChannelRegistry => new NotificationChannelRegistry([
                $app->make(TelegramNotificationChannel::class),
            ]),
        );
        $this->app->singleton(
            ConditionEvaluatorRegistry::class,
            fn (): ConditionEvaluatorRegistry => new ConditionEvaluatorRegistry([
                new BookingStatusConditionEvaluator,
                new ClientLanguageConditionEvaluator,
            ]),
        );
        $this->app->bind(ScenarioRecipientResolver::class, OrganizationScenarioRecipientResolver::class);
        $this->app->bind(NotificationTemplateRenderer::class, ScenarioTemplateRenderer::class);
    }

    public function boot(): void
    {
        RateLimiter::for('portal-telegram-auth', static fn (Request $request): Limit => Limit::perMinute(20)
            ->by('portal-telegram-auth|'.$request->ip()));
        RateLimiter::for('portal-email-request', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('portal-email-request|'.$request->ip()));
        RateLimiter::for('portal-email-verify', static fn (Request $request): Limit => Limit::perMinute(30)
            ->by('portal-email-verify|'.$request->ip()));
        RateLimiter::for('portal-telegram-web-request', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('portal-telegram-web-request|'.$request->ip()));
        RateLimiter::for('portal-telegram-web-status', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by('portal-telegram-web-status|'.$request->session()->getId()));

        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(ClientChannelIdentity::class, ClientChannelIdentityPolicy::class);
        Gate::policy(ClientConsent::class, ClientConsentPolicy::class);
        Gate::policy(OrganizationSetting::class, OrganizationSettingPolicy::class);
        Gate::policy(OrganizationFeatureFlag::class, OrganizationFeatureFlagPolicy::class);
        Gate::policy(OrganizationCredential::class, OrganizationCredentialPolicy::class);
        Gate::policy(AuditEvent::class, AuditEventPolicy::class);
        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(Specialist::class, SpecialistPolicy::class);
        Gate::policy(SpecialistServiceAssignment::class, SpecialistServiceAssignmentPolicy::class);
        Gate::policy(ScheduleException::class, ScheduleExceptionPolicy::class);
        Gate::policy(UnavailablePeriod::class, UnavailablePeriodPolicy::class);
        Gate::policy(ContentSection::class, ContentSectionPolicy::class);
    }
}
