<?php

namespace Tests\Feature\AI;

use App\Filament\Resources\AiProviders\Pages\ListAiProviders;
use App\Models\User;
use App\Modules\AI\Application\Actions\SetAiModelEnabled;
use App\Modules\AI\Application\Actions\UpdateAiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\AuditEvent;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class AiConfigurationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_and_model_can_be_disabled_and_restored_without_deleting_configuration(): void
    {
        [$organization, $admin] = $this->fixture();
        $provider = $this->provider($organization);
        $model = $this->model($organization, $provider);

        app(UpdateAiProviderConfiguration::class)->handle($admin, $provider, ['is_enabled' => false]);
        app(SetAiModelEnabled::class)->handle($admin, $model, false);

        self::assertFalse($provider->fresh()->is_enabled);
        self::assertFalse($model->fresh()->is_enabled);
        self::assertDatabaseHas('ai_provider_configurations', ['id' => $provider->getKey()]);
        self::assertDatabaseHas('ai_model_configurations', ['id' => $model->getKey()]);

        app(UpdateAiProviderConfiguration::class)->handle($admin, $provider->fresh(), ['is_enabled' => true]);
        app(SetAiModelEnabled::class)->handle($admin, $model->fresh(), true);

        self::assertTrue($provider->fresh()->is_enabled);
        self::assertTrue($model->fresh()->is_enabled);
        self::assertSame(2, AuditEvent::query()->where('action', 'ai.provider_config.updated')->count());
        self::assertSame(2, AuditEvent::query()->where('action', 'ai.model_config.updated')->count());
    }

    public function test_provider_list_exposes_direct_disable_action(): void
    {
        [$organization, $admin] = $this->fixture();
        $provider = $this->provider($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ListAiProviders::class)
            ->callTableAction('disable', $provider);

        self::assertFalse($provider->fresh()->is_enabled);
    }

    public function test_model_lifecycle_is_tenant_scoped(): void
    {
        [$organization, $admin] = $this->fixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignProvider = $this->provider($foreignOrganization);
        $foreignModel = $this->model($foreignOrganization, $foreignProvider);
        app(OrganizationContext::class)->set($organization);

        $this->expectException(AuthorizationException::class);
        app(SetAiModelEnabled::class)->handle($admin, $foreignModel, false);
    }

    public function test_provider_lifecycle_is_tenant_scoped(): void
    {
        [$organization] = $this->fixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignAdmin = User::factory()->forOrganization($foreignOrganization, OrganizationRole::Administrator)->create();
        $provider = $this->provider($organization);
        app(OrganizationContext::class)->set($organization);

        $this->expectException(AuthorizationException::class);
        app(UpdateAiProviderConfiguration::class)->handle($foreignAdmin, $provider, ['is_enabled' => false]);
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function provider(Organization $organization): AiProviderConfiguration
    {
        return AiProviderConfiguration::query()->create([
            'organization_id' => $organization->getKey(),
            'provider_name' => 'openai',
            'display_name' => 'AI connection',
            'is_enabled' => true,
            'options' => [],
        ]);
    }

    private function model(Organization $organization, AiProviderConfiguration $provider): AiModelConfiguration
    {
        $pricing = new AiPricingSnapshot;

        return AiModelConfiguration::query()->create([
            'organization_id' => $organization->getKey(),
            'provider_config_id' => $provider->getKey(),
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'AI model',
            'is_enabled' => true,
            'capabilities' => ['client_companion'],
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => 1,
        ]);
    }
}
