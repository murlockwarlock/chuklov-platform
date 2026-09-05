<?php

namespace Tests\Feature\AI;

use App\Filament\Pages\AiMonitoringOverview;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AiMonitoringOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_overview_aggregates_models_and_caps_provider_rows(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        $firstProvider = null;
        for ($providerNumber = 1; $providerNumber <= AiMonitoringOverview::PROVIDER_OVERVIEW_LIMIT + 1; $providerNumber++) {
            $provider = AiProviderConfiguration::create([
                'organization_id' => $organization->id,
                'provider_name' => "provider-{$providerNumber}",
                'display_name' => "Provider {$providerNumber}",
            ]);
            $firstProvider ??= $provider;
        }

        self::assertNotNull($firstProvider);

        AiModelConfiguration::create([
            'organization_id' => $organization->id,
            'provider_config_id' => $firstProvider->id,
            'model_name' => 'bounded-model',
            'display_name' => 'Bounded model',
        ]);

        $viewData = (new AiMonitoringOverview)->getViewData();

        self::assertCount(AiMonitoringOverview::PROVIDER_OVERVIEW_LIMIT, $viewData['providers']);
        self::assertFalse($viewData['providers']->first()->relationLoaded('models'));
        self::assertSame(1, (int) $viewData['providers']->first()->models_count);
        self::assertFalse($viewData['clientCompanion']['ready']);
        self::assertContains('Промпт клиентского компаньона не настроен.', $viewData['clientCompanion']['issues']);
        self::assertContains('Для модели клиентского компаньона нет активной версии с нужным сценарием.', $viewData['clientCompanion']['issues']);
    }

    public function test_monitoring_overview_reports_provider_outage_separately_from_missing_configuration(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        $prompt = AiPrompt::create([
            'organization_id' => $organization->id,
            'key' => 'client_companion',
            'name' => 'Client companion',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $organization->id,
            'prompt_id' => $prompt->id,
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'You are a safe companion.',
            'user_prompt_template' => '{{current_message}}',
            'variables_schema' => [],
            'parameter_config' => [],
            'context_policy' => [],
            'allowed_tools' => [],
            'activated_at' => now(),
        ]);
        $prompt->update(['active_version_id' => $version->id]);

        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->id,
            'provider_name' => 'deepseek',
            'display_name' => 'DeepSeek',
            'is_enabled' => true,
            'health_status' => ProviderHealthStatus::Unavailable,
        ]);
        $model = AiModelConfiguration::create([
            'organization_id' => $organization->id,
            'provider_config_id' => $provider->id,
            'model_name' => 'deepseek-chat',
            'display_name' => 'DeepSeek Chat',
            'is_enabled' => true,
            'lifecycle_status' => 'active',
            'capabilities' => [AiCapability::ClientCompanion->value],
        ]);
        $release = AiModelRelease::create([
            'organization_id' => $organization->id,
            'model_config_id' => $model->id,
            'release_number' => 1,
            'status' => 'active',
            'provider_name' => 'deepseek',
            'model_name' => 'deepseek-chat',
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => [],
            'activated_at' => now(),
        ]);
        $model->update(['active_release_id' => $release->id]);

        $viewData = (new AiMonitoringOverview)->getViewData();

        self::assertSame('provider_unavailable', $viewData['clientCompanion']['status']);
        self::assertContains('Провайдер клиентского компаньона временно недоступен.', $viewData['clientCompanion']['issues']);
        self::assertNotContains('Нет активной модели для клиентского компаньона.', $viewData['clientCompanion']['issues']);
    }

    public function test_monitoring_overview_marks_a_disabled_companion_capability_as_disabled(): void
    {
        $organization = Organization::factory()->create();
        config()->set('tenancy.default_organization_id', $organization->id);
        app(OrganizationContext::class)->set($organization);

        AiOrganizationSafetyControl::create([
            'organization_id' => $organization->id,
            'is_ai_globally_enabled' => true,
            'disabled_capabilities' => [AiCapability::ClientCompanion->value],
        ]);

        $viewData = (new AiMonitoringOverview)->getViewData();

        self::assertSame('disabled', $viewData['clientCompanion']['status']);
        self::assertContains('Сценарий клиентского компаньона отключён в ограничениях AI.', $viewData['clientCompanion']['issues']);
    }
}
