<?php

namespace Tests\Feature\AI;

use App\Filament\Pages\AiMonitoringOverview;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
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
    }
}
