<?php

namespace App\Providers;

use App\Modules\AI\Application\Actions\InvalidateAiProviderHealthForCredential;
use App\Modules\AI\Domain\Contracts\AiContextAssemblerInterface;
use App\Modules\AI\Domain\Contracts\AiOutputValidatorInterface;
use App\Modules\AI\Domain\Contracts\AiPricingCalculatorInterface;
use App\Modules\AI\Domain\Contracts\AiPromptRendererInterface;
use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Contracts\AiToolRegistryInterface;
use App\Modules\AI\Domain\Contracts\AiWorkflowEngine;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Infrastructure\Context\AiContextAssembler;
use App\Modules\AI\Infrastructure\Engine\LaravelAiWorkflowEngine;
use App\Modules\AI\Infrastructure\Output\JsonSchemaOutputValidator;
use App\Modules\AI\Infrastructure\Pricing\DefaultAiPricingCalculator;
use App\Modules\AI\Infrastructure\Prompt\SafePromptRenderer;
use App\Modules\AI\Infrastructure\Providers\AiProviderEndpointGuard;
use App\Modules\AI\Infrastructure\Providers\BoundedBedrockProvider;
use App\Modules\AI\Infrastructure\Safety\AtomicAiSafetyBudgetManager;
use App\Modules\AI\Infrastructure\Tools\AiToolRegistry;
use App\Modules\AI\Infrastructure\Tools\SearchKnowledgeBaseTool;
use App\Modules\Attachments\Domain\Contracts\AttachmentStorageInterface;
use App\Modules\Attachments\Domain\Models\MedicalAttachment;
use App\Modules\Attachments\Infrastructure\Storage\PrivateMedicalAttachmentStorage;
use App\Modules\B2B\Application\BookingZoomMeetingLifecycle;
use App\Modules\B2B\Domain\Contracts\VideoMeetingProvider;
use App\Modules\B2B\Infrastructure\Video\ZoomVideoMeetingProvider;
use App\Modules\Channels\Application\NotificationChannelRegistry;
use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Infrastructure\Telegram\TelegramMessagingChannel;
use App\Modules\Channels\Infrastructure\Telegram\TelegramNotificationChannel;
use App\Modules\ClientPortal\Application\ClientPortalContext;
use App\Modules\Content\Domain\Contracts\ContentMediaStorageInterface;
use App\Modules\Content\Domain\Models\ContentSection;
use App\Modules\Content\Infrastructure\Storage\FilesystemContentMediaStorage;
use App\Modules\Finance\Domain\Contracts\PaymentGateway;
use App\Modules\Finance\Domain\Contracts\ReceiptStorage;
use App\Modules\Finance\Domain\Models\FinancialObligation;
use App\Modules\Finance\Domain\Models\FinancialReceipt;
use App\Modules\Finance\Infrastructure\Fake\FakePaymentGateway;
use App\Modules\Finance\Infrastructure\Storage\PrivateReceiptStorage;
use App\Modules\Identity\Domain\Contracts\EmailVerificationCodeSender;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Identity\Domain\Models\ClientChannelIdentity;
use App\Modules\Identity\Domain\Models\ClientConsent;
use App\Modules\Identity\Infrastructure\Mail\LaravelEmailVerificationCodeSender;
use App\Modules\Knowledge\Domain\Contracts\EmbeddingGenerator;
use App\Modules\Knowledge\Domain\Contracts\KnowledgeRetriever;
use App\Modules\Knowledge\Domain\Models\KnowledgeSource;
use App\Modules\Knowledge\Infrastructure\LaravelEmbeddingGenerator;
use App\Modules\Knowledge\Infrastructure\PgvectorKnowledgeRetriever;
use App\Modules\MedicalProfiles\Application\GetMedicalProfile;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalKeyResolverInterface;
use App\Modules\MedicalProfiles\Infrastructure\Encryption\AppKeyMedicalKeyResolver;
use App\Modules\MedicalProfiles\Infrastructure\Encryption\MedicalDataEncryptor;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Organizations\Domain\Models\OrganizationSetting;
use App\Modules\Scenarios\Application\BookingNextBookingConditionEvaluator;
use App\Modules\Scenarios\Application\BookingStatusConditionEvaluator;
use App\Modules\Scenarios\Application\ClientLanguageConditionEvaluator;
use App\Modules\Scenarios\Application\ClientMarketingConsentConditionEvaluator;
use App\Modules\Scenarios\Application\ConditionEvaluatorRegistry;
use App\Modules\Scenarios\Application\FinancialOutstandingDebtConditionEvaluator;
use App\Modules\Scenarios\Application\OnboardingCompletedConditionEvaluator;
use App\Modules\Scenarios\Application\OnboardingStageConditionEvaluator;
use App\Modules\Scenarios\Application\OrganizationScenarioRecipientResolver;
use App\Modules\Scenarios\Application\ScenarioTemplateRenderer;
use App\Modules\Scenarios\Domain\Contracts\NotificationTemplateRenderer;
use App\Modules\Scenarios\Domain\Contracts\ScenarioRecipientResolver;
use App\Modules\Scheduling\Domain\Contracts\BookingVideoMeetingLifecycle;
use App\Modules\Scheduling\Domain\Models\Booking;
use App\Modules\Scheduling\Domain\Models\ScheduleException;
use App\Modules\Scheduling\Domain\Models\SpecialistServiceAssignment;
use App\Modules\Scheduling\Domain\Models\UnavailablePeriod;
use App\Modules\Security\Domain\Events\OrganizationCredentialReplaced;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use App\Modules\Services\Domain\Contracts\ServiceMediaStorageInterface;
use App\Modules\Services\Domain\Models\Service;
use App\Modules\Services\Infrastructure\Storage\FilesystemServiceMediaStorage;
use App\Modules\Sessions\Application\GetSession;
use App\Modules\Sessions\Domain\Models\MedicalSession;
use App\Modules\Specialists\Domain\Models\Specialist;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Policies\AiEvalSuitePolicy;
use App\Policies\AiModelConfigurationPolicy;
use App\Policies\AiOrganizationSafetyControlPolicy;
use App\Policies\AiPromptPolicy;
use App\Policies\AiProviderConfigurationPolicy;
use App\Policies\AiRunPolicy;
use App\Policies\AuditEventPolicy;
use App\Policies\BookingPolicy;
use App\Policies\ClientChannelIdentityPolicy;
use App\Policies\ClientConsentPolicy;
use App\Policies\ClientPolicy;
use App\Policies\ContentSectionPolicy;
use App\Policies\FinancialObligationPolicy;
use App\Policies\FinancialReceiptPolicy;
use App\Policies\KnowledgeSourcePolicy;
use App\Policies\MedicalAttachmentPolicy;
use App\Policies\MedicalSessionPolicy;
use App\Policies\OrganizationCredentialPolicy;
use App\Policies\OrganizationFeatureFlagPolicy;
use App\Policies\OrganizationSettingPolicy;
use App\Policies\ScheduleExceptionPolicy;
use App\Policies\ServicePolicy;
use App\Policies\SpecialistPolicy;
use App\Policies\SpecialistServiceAssignmentPolicy;
use App\Policies\SurveyAttemptPolicy;
use App\Policies\SurveyDefinitionPolicy;
use App\Policies\UnavailablePeriodPolicy;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;
use Psr\Http\Message\RequestInterface;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->afterResolving(AiManager::class, static function (AiManager $manager, Application $app): void {
            $manager->extend(
                'bedrock',
                fn (Application $application, array $config): BoundedBedrockProvider => new BoundedBedrockProvider(
                    $config,
                    $application->make(Dispatcher::class),
                ),
            );
        });
        $this->app->scoped(OrganizationContext::class);
        $this->app->scoped(ClientPortalContext::class);
        $this->app->scoped(GetMedicalProfile::class);
        $this->app->scoped(GetSession::class);
        $this->app->bind(EmailVerificationCodeSender::class, LaravelEmailVerificationCodeSender::class);
        $this->app->bind(MessagingChannel::class, TelegramMessagingChannel::class);
        $this->app->bind(VideoMeetingProvider::class, ZoomVideoMeetingProvider::class);
        $this->app->bind(BookingVideoMeetingLifecycle::class, BookingZoomMeetingLifecycle::class);
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
                new BookingNextBookingConditionEvaluator,
                new ClientLanguageConditionEvaluator,
                new ClientMarketingConsentConditionEvaluator,
                new OnboardingCompletedConditionEvaluator,
                new OnboardingStageConditionEvaluator,
                new FinancialOutstandingDebtConditionEvaluator,
            ]),
        );
        $this->app->bind(PaymentGateway::class, FakePaymentGateway::class);
        $this->app->bind(ReceiptStorage::class, PrivateReceiptStorage::class);
        $this->app->bind(MedicalKeyResolverInterface::class, AppKeyMedicalKeyResolver::class);
        $this->app->bind(MedicalEncryptorInterface::class, MedicalDataEncryptor::class);
        $this->app->bind(AttachmentStorageInterface::class, PrivateMedicalAttachmentStorage::class);
        $this->app->bind(ContentMediaStorageInterface::class, FilesystemContentMediaStorage::class);
        $this->app->bind(ServiceMediaStorageInterface::class, FilesystemServiceMediaStorage::class);
        $this->app->bind(EmbeddingGenerator::class, LaravelEmbeddingGenerator::class);
        $this->app->bind(KnowledgeRetriever::class, PgvectorKnowledgeRetriever::class);
        $this->app->bind(ScenarioRecipientResolver::class, OrganizationScenarioRecipientResolver::class);
        $this->app->bind(NotificationTemplateRenderer::class, ScenarioTemplateRenderer::class);
        $this->app->bind(AiWorkflowEngine::class, LaravelAiWorkflowEngine::class);
        $this->app->bind(AiPricingCalculatorInterface::class, DefaultAiPricingCalculator::class);
        $this->app->bind(AiPromptRendererInterface::class, SafePromptRenderer::class);
        $this->app->bind(AiOutputValidatorInterface::class, JsonSchemaOutputValidator::class);
        $this->app->bind(AiSafetyBudgetManagerInterface::class, AtomicAiSafetyBudgetManager::class);
        $this->app->bind(AiContextAssemblerInterface::class, AiContextAssembler::class);
        $this->app->singleton(
            AiToolRegistryInterface::class,
            fn (Application $app): AiToolRegistry => new AiToolRegistry([
                $app->make(SearchKnowledgeBaseTool::class),
            ]),
        );
    }

    public function boot(): void
    {
        Http::globalMiddleware(static function (callable $handler): Closure {
            return static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                $request = AiProviderEndpointGuard::guardRequest($request);
                $options['allow_redirects'] = false;

                return $handler($request, AiProviderEndpointGuard::pinDns($request, $options));
            };
        });

        Event::listen(OrganizationCredentialReplaced::class, static function (OrganizationCredentialReplaced $event): void {
            app(InvalidateAiProviderHealthForCredential::class)->handle(
                organizationId: $event->organizationId,
                provider: $event->provider,
                credentialId: $event->credentialId,
            );
        });

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
        RateLimiter::for('portal-companion-send', static function (Request $request): Limit {
            $clientId = (string) $request->session()->get('client_portal.client_id', 'anonymous');

            return Limit::perMinute((int) config('ai.companion.portal_rate_limit_per_minute', 12))
                ->by('portal-companion-send|'.$clientId.'|'.$request->ip());
        });

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
        Gate::policy(FinancialObligation::class, FinancialObligationPolicy::class);
        Gate::policy(FinancialReceipt::class, FinancialReceiptPolicy::class);
        Gate::policy(MedicalAttachment::class, MedicalAttachmentPolicy::class);
        Gate::policy(MedicalSession::class, MedicalSessionPolicy::class);
        Gate::policy(SurveyDefinition::class, SurveyDefinitionPolicy::class);
        Gate::policy(SurveyAttempt::class, SurveyAttemptPolicy::class);
        Gate::policy(KnowledgeSource::class, KnowledgeSourcePolicy::class);
        Gate::policy(AiRun::class, AiRunPolicy::class);
        Gate::policy(AiPrompt::class, AiPromptPolicy::class);
        Gate::policy(AiProviderConfiguration::class, AiProviderConfigurationPolicy::class);
        Gate::policy(AiModelConfiguration::class, AiModelConfigurationPolicy::class);
        Gate::policy(AiEvalSuite::class, AiEvalSuitePolicy::class);
        Gate::policy(AiOrganizationSafetyControl::class, AiOrganizationSafetyControlPolicy::class);
    }
}
