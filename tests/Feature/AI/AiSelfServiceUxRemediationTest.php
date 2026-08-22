<?php

namespace Tests\Feature\AI;

use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\AiProviders\RelationManagers\ModelsRelationManager;
use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAiEvaluationSuite;
use App\Modules\AI\Application\Actions\CreateAiPrompt;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\CreateModelConfiguration;
use App\Modules\AI\Application\Actions\UpdateAiSafetyControl;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Exceptions\AiPricingProfileIncompleteException;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiModelCatalog;
use App\Modules\AI\Domain\Registry\AiModelDefinition;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

final class AiSelfServiceUxRemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_owner_can_connect_a_provider_with_an_api_key_without_exposing_the_secret(): void
    {
        [$organization, $owner] = $this->organizationFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($owner);
        $component = Livewire::test(CreateAiProvider::class)
            ->fillForm([
                'provider_name' => 'openai',
                'display_name' => 'OpenAI для Chuklov',
                'api_key' => 'sk-owner-self-service-secret',
                'is_enabled' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $provider = AiProviderConfiguration::query()->where('organization_id', $organization->getKey())->sole();
        $credential = OrganizationCredential::query()->where('id', $provider->credential_id)->sole();

        self::assertSame('openai', $credential->provider);
        self::assertSame('sk-owner-self-service-secret', $credential->credentials['api_key']);
        self::assertStringNotContainsString('sk-owner-self-service-secret', (string) DB::table('organization_credentials')->where('id', $credential->getKey())->value('credentials'));
        self::assertStringNotContainsString('sk-owner-self-service-secret', $component->html());
    }

    public function test_catalog_selection_resolves_the_canonical_model_and_hydrates_known_metadata(): void
    {
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', [$this->catalogEntry('catalog-model', [
            'image_input',
            'document_input',
        ])]);
        $provider = $this->provider('openai');

        self::assertSame('Каталожная модель · Catalog family', AiModelCatalog::optionsForProvider('openai')['catalog-model']);
        self::assertSame('Другая модель / Указать вручную', AiModelCatalog::optionsForProvider('openai')[AiModelCatalog::CUSTOM_MODEL]);

        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => 'catalog-model',
            'display_name' => 'Основная модель',
            'capabilities' => [AiCapability::ClientCompanion->value],
            'failover_priority' => 1,
        ]);

        self::assertSame('catalog-model', $model->model_name);
        self::assertSame('Основная модель', $model->display_name);
        self::assertContains(AiCapability::ClientCompanion->value, $model->capabilities);
        self::assertContains(AiModelModality::ImageInput->value, $model->capabilities);
        self::assertContains(AiModelModality::DocumentInput->value, $model->capabilities);
        self::assertSame(AiPricingSnapshot::SOURCE_CATALOG, $model->getPricingSnapshot()->pricingSource);
        self::assertSame(250, $model->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $model->getPricingSnapshot()->outputCostPerMillionMinorUnits);
        self::assertTrue($model->getPricingSnapshot()->isComplete());
    }

    public function test_default_catalog_contains_only_current_priced_choices_and_excludes_legacy_models(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 22, 12, 0, 0, 'UTC'));
        config()->set('ai.model_catalog', $this->defaultCatalog());

        $definitions = AiModelCatalog::all();

        self::assertSame([
            'gpt-5.6-terra',
            'claude-sonnet-5',
            'gemini-2.5-flash',
        ], array_map(
            static fn (AiModelDefinition $definition): string => $definition->modelName,
            $definitions,
        ));

        foreach ($definitions as $definition) {
            self::assertSame('active', $definition->lifecycleStatus->value);
            self::assertNotNull($definition->pricing);
            self::assertSame(AiPricingSnapshot::SOURCE_CATALOG, $definition->pricing->pricingSource);
            self::assertSame('2026-08-22', $definition->pricingAsOf);
            self::assertNotSame('', $definition->catalogSource);
        }

        self::assertArrayNotHasKey('gpt-4o-mini', AiModelCatalog::optionsForProvider('openai'));
        self::assertArrayNotHasKey('gpt-4o', AiModelCatalog::optionsForProvider('openai'));
        self::assertArrayNotHasKey('claude-3-5-sonnet', AiModelCatalog::optionsForProvider('anthropic'));
        self::assertArrayNotHasKey('gemini-2.0-flash', AiModelCatalog::optionsForProvider('gemini'));
    }

    public function test_known_catalog_models_are_priced_without_manual_token_entry(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 22, 12, 0, 0, 'UTC'));
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', $this->defaultCatalog());

        $expected = [
            ['openai', 'gpt-5.6-terra', 200, 1200, 20, 250],
            ['anthropic', 'claude-sonnet-5', 200, 1000, 20, null],
            ['gemini', 'gemini-2.5-flash', 30, 250, 3, null],
        ];

        foreach ($expected as [$providerName, $modelName, $input, $output, $cacheRead, $cacheWrite]) {
            $model = app(CreateModelConfiguration::class)->handle($owner, $this->provider($providerName), [
                'model_selection' => $modelName,
                'display_name' => $modelName.' в Chuklov',
                'capabilities' => [AiCapability::GeneralAssistant->value],
            ]);
            $pricing = $model->getPricingSnapshot();

            self::assertSame($modelName, $model->model_name);
            self::assertSame(AiPricingSnapshot::SOURCE_CATALOG, $pricing->pricingSource);
            self::assertSame($input, $pricing->inputCostPerMillionMinorUnits);
            self::assertSame($output, $pricing->outputCostPerMillionMinorUnits);
            self::assertSame($cacheRead, $pricing->cacheReadInputCostPerMillionMinorUnits);
            self::assertSame($cacheWrite, $pricing->cacheWriteInputCostPerMillionMinorUnits);
            self::assertTrue($pricing->isComplete());
        }
    }

    public function test_retired_catalog_models_are_hidden_for_new_records_and_legacy_models_render_as_custom(): void
    {
        [$organization, $owner] = $this->organizationFixture();
        $retired = $this->catalogEntry('retired-model', []);
        $retired['display_name'] = 'Старая модель';
        $retired['lifecycle'] = 'retired';
        config()->set('ai.model_catalog', [$this->catalogEntry('active-model', []), $retired]);

        $newOptions = AiModelCatalog::optionsForProvider('openai');
        self::assertArrayHasKey('active-model', $newOptions);
        self::assertArrayNotHasKey('retired-model', $newOptions);
        self::assertStringContainsString(
            'Снята с использования',
            AiModelCatalog::optionsForProvider('openai', 'retired-model')['retired-model'],
        );

        config()->set('ai.model_catalog', []);
        $provider = $this->provider('openai');
        $legacy = AiModelConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_config_id' => $provider->getKey(),
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'Сохранённая старая модель',
            'capabilities' => [AiCapability::GeneralAssistant->value],
            'pricing_snapshot' => (new AiPricingSnapshot(
                inputCostPerMillionMinorUnits: 250,
                outputCostPerMillionMinorUnits: 1000,
                cacheReadInputCostPerMillionMinorUnits: 25,
                cacheWriteInputCostPerMillionMinorUnits: 50,
                reasoningCostPerMillionMinorUnits: 0,
            ))->toArray(),
            'failover_priority' => 1,
            'is_enabled' => false,
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($owner);
        Livewire::test(ModelsRelationManager::class, [
            'ownerRecord' => $provider,
            'pageClass' => EditAiProvider::class,
        ])
            ->mountTableAction('edit', $legacy)
            ->assertTableActionDataSet([
                'model_selection' => AiModelCatalog::CUSTOM_MODEL,
                'model_name' => 'gpt-4o-mini',
            ]);
    }

    public function test_custom_model_and_manual_pricing_path_remains_available(): void
    {
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', []);
        $provider = $this->provider('openai');

        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'future-provider-model',
            'display_name' => 'Новая модель провайдера',
            'model_modalities' => [AiModelModality::DocumentInput->value],
            'capabilities' => [AiCapability::GeneralAssistant->value],
            'input_cost_per_million' => '1.25',
            'output_cost_per_million' => '2.50',
            'cache_read_input_cost_per_million' => '0.00',
            'cache_write_input_cost_per_million' => '0.00',
            'reasoning_cost_per_million' => '0.00',
            'fixed_request_cost_applicable' => false,
            'failover_priority' => 2,
        ]);

        self::assertSame('future-provider-model', $model->model_name);
        self::assertSame(AiPricingSnapshot::SOURCE_MANUAL, $model->getPricingSnapshot()->pricingSource);
        self::assertSame(125, $model->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(250, $model->getPricingSnapshot()->outputCostPerMillionMinorUnits);
        self::assertContains(AiModelModality::DocumentInput->value, $model->capabilities);
        self::assertSame(2, $model->failover_priority);
    }

    public function test_unknown_catalog_pricing_is_not_treated_as_zero_and_cannot_be_activated(): void
    {
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', [[
            'provider' => 'openai',
            'model' => 'unpriced-model',
            'display_name' => 'Модель без стоимости',
            'family' => 'Unknown family',
            'supported_capabilities' => ['text_generation'],
            'modalities' => [],
            'pricing' => null,
            'lifecycle' => 'active',
        ]]);
        $provider = $this->provider('openai');
        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => 'unpriced-model',
            'display_name' => 'Модель без стоимости',
            'capabilities' => [AiCapability::GeneralAssistant->value],
        ]);

        self::assertSame(AiPricingSnapshot::SOURCE_UNKNOWN, $model->getPricingSnapshot()->pricingSource);
        self::assertFalse($model->getPricingSnapshot()->isComplete());

        $this->expectException(AiPricingProfileIncompleteException::class);
        app(CreateAndActivateModelRelease::class)->handle($owner, $model, []);
    }

    public function test_prompt_and_evaluation_keys_are_generated_collision_safe_and_legacy_keys_remain_compatible(): void
    {
        [, $owner] = $this->organizationFixture();

        $promptOne = app(CreateAiPrompt::class)->handle($owner, [
            'name' => 'Проверка осанки',
            'capability' => AiCapability::PostureAnalysis->value,
        ]);
        $promptTwo = app(CreateAiPrompt::class)->handle($owner, [
            'name' => 'Проверка осанки',
            'capability' => AiCapability::PostureAnalysis->value,
        ]);
        $legacyPrompt = app(CreateAiPrompt::class)->handle($owner, [
            'key' => 'legacy_posture_prompt',
            'name' => 'Старый промпт',
            'capability' => AiCapability::PostureAnalysis->value,
        ]);

        self::assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $promptOne->key);
        self::assertSame($promptOne->key.'-2', $promptTwo->key);
        self::assertSame('legacy_posture_prompt', $legacyPrompt->key);

        try {
            app(CreateAiPrompt::class)->handle($owner, [
                'key' => 'legacy_posture_prompt',
                'name' => 'Дубликат старого промпта',
                'capability' => AiCapability::PostureAnalysis->value,
            ]);
            self::fail('A manually supplied duplicate technical key must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }

        $evaluationOne = app(CreateAiEvaluationSuite::class)->handle($owner, [
            'name' => 'Проверка качества осанки',
            'capability' => AiCapability::PostureAnalysis->value,
        ]);
        $evaluationTwo = app(CreateAiEvaluationSuite::class)->handle($owner, [
            'name' => 'Проверка качества осанки',
            'capability' => AiCapability::PostureAnalysis->value,
        ]);

        self::assertSame($evaluationOne->key.'-2', $evaluationTwo->key);
        self::assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $evaluationOne->key);
    }

    public function test_provider_edit_keeps_api_key_blank_preserves_it_when_unchanged_and_rotates_through_security(): void
    {
        [$organization, $owner] = $this->organizationFixture();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($owner);

        Livewire::test(CreateAiProvider::class)
            ->fillForm([
                'provider_name' => 'openai',
                'display_name' => 'OpenAI для проверки',
                'api_key' => 'sk-provider-edit-original',
                'is_enabled' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $provider = AiProviderConfiguration::query()
            ->where('organization_id', $organization->getKey())
            ->sole();
        $credential = OrganizationCredential::query()->whereKey($provider->credential_id)->sole();
        $originalRevision = $credential->revision_id;
        $originalCiphertext = (string) DB::table('organization_credentials')
            ->where('id', $credential->getKey())
            ->value('credentials');

        $edit = Livewire::test(EditAiProvider::class, ['record' => $provider->getRouteKey()]);
        /** @var EditAiProvider $editPage */
        $editPage = $edit->instance();
        $formState = $editPage->form->getState();
        self::assertTrue(blank($formState['api_key'] ?? null));
        self::assertStringNotContainsString('sk-provider-edit-original', serialize($formState));
        self::assertStringNotContainsString('sk-provider-edit-original', $edit->html());

        $edit
            ->fillForm([
                'display_name' => 'OpenAI без ротации',
                'is_enabled' => true,
                'api_key' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $provider->refresh();
        $credential->refresh();
        self::assertSame($credential->getKey(), $provider->credential_id);
        self::assertSame($originalRevision, $credential->revision_id);
        self::assertSame($originalCiphertext, (string) DB::table('organization_credentials')
            ->where('id', $credential->getKey())
            ->value('credentials'));

        $rotatedEdit = Livewire::test(EditAiProvider::class, ['record' => $provider->getRouteKey()])
            ->fillForm([
                'display_name' => 'OpenAI после ротации',
                'is_enabled' => true,
                'api_key' => 'sk-provider-edit-rotated',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        /** @var EditAiProvider $rotatedEditPage */
        $rotatedEditPage = $rotatedEdit->instance();
        $credential->refresh();
        self::assertSame('sk-provider-edit-rotated', $credential->credentials['api_key']);
        self::assertNotSame($originalRevision, $credential->revision_id);
        self::assertStringNotContainsString('sk-provider-edit-rotated', $rotatedEdit->html());
        self::assertStringNotContainsString('sk-provider-edit-original', $rotatedEdit->html());
        self::assertStringNotContainsString('sk-provider-edit-rotated', serialize($rotatedEditPage->form->getState()));
        self::assertStringNotContainsString('sk-provider-edit-original', serialize($rotatedEditPage->form->getState()));

        $auditMetadata = DB::table('audit_events')
            ->where('organization_id', $organization->getKey())
            ->where('action', 'organization.credential.replaced')
            ->latest('id')
            ->value('metadata');
        self::assertStringNotContainsString('sk-provider-edit-original', (string) $auditMetadata);
        self::assertStringNotContainsString('sk-provider-edit-rotated', (string) $auditMetadata);
    }

    public function test_budget_partial_update_preserves_other_exact_runtime_limits(): void
    {
        [, $owner] = $this->organizationFixture();
        app(UpdateAiSafetyControl::class)->handle($owner, [
            'is_ai_globally_enabled' => true,
            'max_daily_spend' => '50.00',
            'max_tokens_per_run' => 8192,
            'max_runs_per_minute' => 60,
        ]);

        $control = app(UpdateAiSafetyControl::class)->handle($owner, ['max_daily_spend' => '12.34']);

        self::assertSame(1234, $control->max_daily_spend_minor_units);
        self::assertSame(8192, $control->max_tokens_per_run);
        self::assertSame(60, $control->max_runs_per_minute);
    }

    /** @return array{Organization, User} */
    private function organizationFixture(): array
    {
        $organization = Organization::query()->create([
            'name' => 'AI Self-Service Clinic',
            'slug' => 'ai-self-service-clinic',
            'timezone' => 'UTC',
        ]);
        $owner = User::factory()->forOrganization($organization, OrganizationRole::Owner)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $owner];
    }

    private function provider(string $providerName): AiProviderConfiguration
    {
        return AiProviderConfiguration::create([
            'organization_id' => app(OrganizationContext::class)->id(),
            'provider_name' => $providerName,
            'display_name' => ucfirst($providerName),
            'is_enabled' => true,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function defaultCatalog(): array
    {
        /** @var array{model_catalog: list<array<string, mixed>>} $config */
        $config = require base_path('config/ai.php');

        return $config['model_catalog'];
    }

    /**
     * @param  list<string>  $modalities
     * @return array<string, mixed>
     */
    private function catalogEntry(string $model, array $modalities): array
    {
        return [
            'provider' => 'openai',
            'model' => $model,
            'display_name' => 'Каталожная модель',
            'family' => 'Catalog family',
            'supported_capabilities' => ['text_generation'],
            'modalities' => $modalities,
            'pricing' => [
                'currency' => 'USD',
                'input_cost_per_million_minor_units' => 250,
                'output_cost_per_million_minor_units' => 1000,
                'cache_read_input_cost_per_million_minor_units' => 25,
                'cache_write_input_cost_per_million_minor_units' => 50,
                'reasoning_cost_per_million_minor_units' => 125,
                'fixed_request_cost_applicable' => false,
                'fixed_request_cost_minor_units' => 0,
                'unsupported_meters' => [],
            ],
            'lifecycle' => 'active',
        ];
    }
}
