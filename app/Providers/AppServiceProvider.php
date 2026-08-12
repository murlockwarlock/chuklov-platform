<?php

namespace App\Providers;

use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Identity\Domain\Contracts\EmailVerificationCodeSender;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Identity\Infrastructure\Mail\LaravelEmailVerificationCodeSender;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Models\Service;
use App\Policies\AuditEventPolicy;
use App\Policies\ClientChannelIdentityPolicy;
use App\Policies\ClientConsentPolicy;
use App\Policies\ClientPolicy;
use App\Policies\OrganizationCredentialPolicy;
use App\Policies\OrganizationFeatureFlagPolicy;
use App\Policies\OrganizationSettingPolicy;
use App\Policies\ServicePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(OrganizationContext::class);
        $this->app->scoped(ClientPortalContext::class);
        $this->app->bind(EmailVerificationCodeSender::class, LaravelEmailVerificationCodeSender::class);
    }

    public function boot(): void
    {
        Gate::policy(Service::class, ServicePolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(ClientChannelIdentity::class, ClientChannelIdentityPolicy::class);
        Gate::policy(ClientConsent::class, ClientConsentPolicy::class);
        Gate::policy(OrganizationSetting::class, OrganizationSettingPolicy::class);
        Gate::policy(OrganizationFeatureFlag::class, OrganizationFeatureFlagPolicy::class);
        Gate::policy(OrganizationCredential::class, OrganizationCredentialPolicy::class);
        Gate::policy(AuditEvent::class, AuditEventPolicy::class);
    }
}
