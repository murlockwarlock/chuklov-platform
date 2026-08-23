<?php

namespace Tests\Feature\AI;

use App\Filament\Pages\AiMonitoringOverview;
use App\Filament\Resources\AiEvaluations\Pages\CreateAiEvaluation;
use App\Filament\Resources\AiPrompts\Pages\EditAiPrompt;
use App\Filament\Resources\AiPrompts\RelationManagers\PromptVersionsRelationManager;
use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\AiProviders\RelationManagers\ModelsRelationManager;
use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAiEvaluationSuite;
use App\Modules\AI\Application\Actions\CreateAiPrompt;
use App\Modules\AI\Application\Actions\CreateAiProviderConfiguration;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\CreateModelConfiguration;
use App\Modules\AI\Application\Actions\CreatePromptDraft;
use App\Modules\AI\Application\Actions\UpdateAiEvaluationSuite;
use App\Modules\AI\Application\Actions\UpdateAiPrompt;
use App\Modules\AI\Application\Actions\UpdateAiProviderConfiguration;
use App\Modules\AI\Application\Actions\UpdateAiSafetyControl;
use App\Modules\AI\Domain\Contracts\AiSafetyBudgetManagerInterface;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Exceptions\AiBudgetExceededException;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiOrganizationSafetyControl;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiProviderCatalog;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\AI\Infrastructure\Providers\AiProviderFactory;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Livewire\Livewire;
use Tests\TestCase;

final class AiAdministrationUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_catalog_and_credential_select_are_human_bounded_and_secret_safe(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $this->resolveFilamentContext($organization, $admin);

        self::assertSame([
            'openai',
            'azure',
            'anthropic',
            'gemini',
            'openrouter',
            'xai',
            'bedrock',
            'openai_compatible',
            'groq',
            'deepseek',
            'ollama',
            'mistral',
        ], array_values(array_filter(
            AiProviderCatalog::keys(),
            static fn (string $providerKey): bool => ! AiProviderCatalog::isSpecialized($providerKey),
        )));

        $genericProviders = array_values(array_filter(
            AiProviderCatalog::keys(),
            static fn (string $providerKey): bool => ! AiProviderCatalog::isSpecialized($providerKey),
        ));
        self::assertSame([
            'cohere',
            'jina',
            'voyageai',
            'eleven',
        ], array_values(array_filter(
            AiProviderCatalog::keys(),
            static fn (string $providerKey): bool => AiProviderCatalog::isSpecialized($providerKey),
        )));

        foreach ($genericProviders as $providerKey) {
            $providerOptions = match ($providerKey) {
                'azure' => ['base_url' => 'https://azure.example'],
                'openai_compatible' => ['base_url' => 'https://compatible.example/v1'],
                'ollama' => ['base_url' => 'http://ollama.example'],
                default => [],
            };

            self::assertInstanceOf(
                TextProvider::class,
                app(AiProviderFactory::class)->createTextProvider(
                    $providerKey,
                    $this->credential($organization, $providerKey, "{$providerKey} credential"),
                    extraConfig: ['provider_options' => $providerOptions],
                ),
            );
        }

        foreach (['cohere', 'jina', 'voyageai', 'eleven'] as $providerKey) {
            self::assertTrue(AiProviderCatalog::isSpecialized($providerKey));
        }

        for ($index = 1; $index <= 55; $index++) {
            $this->credential($organization, 'openai', 'Credential '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        }

        $target = $this->credential($organization, 'openai', 'Z Target Credential');
        $foreignOrganization = Organization::factory()->create();
        $foreign = $this->credential($foreignOrganization, 'openai', 'Z Target Credential');

        $component = Livewire::actingAs($admin)->test(CreateAiProvider::class);
        $providerSelect = $component->instance()->getSchemaComponent('form.provider_name');
        $component->fillForm([
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'is_enabled' => true,
        ]);
        $credentialSelect = $component->instance()->getSchemaComponent('form.credential_id');

        self::assertInstanceOf(Select::class, $providerSelect);
        self::assertSame(AiProviderCatalog::options(), $providerSelect->getOptions());
        self::assertInstanceOf(Select::class, $credentialSelect);
        self::assertTrue($credentialSelect->hasDynamicOptions());
        self::assertTrue($credentialSelect->hasDynamicSearchResults());
        self::assertSame(50, $credentialSelect->getOptionsLimit());

        $initialOptions = $credentialSelect->getOptionsForJs();
        self::assertCount(50, $initialOptions);
        self::assertFalse(collect($initialOptions)->contains('value', (string) $target->getKey()));
        self::assertFalse(collect($initialOptions)->contains('value', (string) $foreign->getKey()));

        $searchResults = $component->instance()->callSchemaComponentMethod(
            'form.credential_id',
            'getSearchResultsForJs',
            ['search' => 'Z Target Credential'],
        );

        self::assertSame([
            [
                'label' => 'Z Target Credential · OpenAI · активны',
                'value' => (string) $target->getKey(),
                'isDisabled' => false,
            ],
        ], $searchResults);
        self::assertFalse(collect($searchResults)->contains('value', (string) $foreign->getKey()));
        self::assertStringNotContainsString('sk-openai-secret', $component->html());

        $component
            ->set('data.credential_id', $target->getKey());
        self::assertStringNotContainsString('sk-openai-secret', serialize($component->instance()->form->getState()));
    }

    public function test_specialized_providers_are_not_exposed_as_generic_model_relations(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_name' => 'cohere',
            'display_name' => 'Cohere Knowledge',
        ]);

        self::assertFalse(ModelsRelationManager::canViewForRecord($provider, EditAiProvider::class));

        try {
            app(CreateModelConfiguration::class)->handle($admin, $provider, [
                'model_selection' => 'embed-v4.0',
                'display_name' => 'Cohere Embeddings',
            ]);
            self::fail('Specialized providers must not create generic chat model configurations.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('specialized configuration', $exception->getMessage());
        }
    }

    public function test_specialized_providers_and_unwired_knowledge_models_are_not_exposed_as_executable_configuration(): void
    {
        [, $admin] = $this->organizationFixture();

        self::assertArrayNotHasKey('cohere', AiProviderCatalog::options());
        self::assertArrayNotHasKey('jina', AiProviderCatalog::options());
        self::assertArrayNotHasKey('voyageai', AiProviderCatalog::options());
        self::assertArrayNotHasKey('eleven', AiProviderCatalog::options());
        self::assertSame([], AiProviderExecutionConfiguration::normalizeOptions('openai', [
            'embedding_model' => 'text-embedding-3-small',
            'reranking_model' => 'rerank-v4.0-fast',
        ]));

        $this->expectException(InvalidArgumentException::class);

        app(CreateAiProviderConfiguration::class)->handle($admin, [
            'provider_name' => 'cohere',
            'display_name' => 'Cohere knowledge',
        ]);
    }

    public function test_provider_server_invariants_reject_unsupported_identity_changes_and_foreign_credentials(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignCredential = $this->credential($foreignOrganization, 'anthropic', 'Foreign credential');
        $localCredential = $this->credential($organization, 'openai', 'Local credential');

        try {
            app(CreateAiProviderConfiguration::class)->handle($admin, [
                'provider_name' => 'unsupported-provider',
                'display_name' => 'Unsupported',
            ]);
            self::fail('An unsupported provider must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('not supported', $exception->getMessage());
        }

        $provider = app(CreateAiProviderConfiguration::class)->handle($admin, [
            'provider_name' => 'openai',
            'display_name' => 'OpenAI production',
            'credential_id' => $localCredential->getKey(),
        ]);

        try {
            app(UpdateAiProviderConfiguration::class)->handle($admin, $provider, ['provider_name' => 'anthropic']);
            self::fail('A configured provider identity must not be changed.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot be changed', $exception->getMessage());
        }

        try {
            app(UpdateAiProviderConfiguration::class)->handle($admin, $provider, ['credential_id' => $foreignCredential->getKey()]);
            self::fail('A credential from another organization must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('credential', strtolower($exception->getMessage()));
        }

        $foreignProvider = AiProviderConfiguration::create([
            'organization_id' => $foreignCredential->organization_id,
            'provider_name' => 'anthropic',
            'display_name' => 'Foreign Anthropic',
        ]);

        try {
            app(UpdateAiProviderConfiguration::class)->handle($admin, $foreignProvider, ['display_name' => 'Tampered']);
            self::fail('A provider from another organization must not be updated.');
        } catch (AuthorizationException) {
            self::assertSame('Foreign Anthropic', $foreignProvider->refresh()->display_name);
        }

        self::assertSame('openai', $provider->refresh()->provider_name);
    }

    public function test_budget_form_round_trips_human_money_preserves_partial_settings_and_runtime_uses_canonical_limit(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $control = AiOrganizationSafetyControl::create([
            'organization_id' => $organization->getKey(),
            'is_ai_globally_enabled' => false,
            'disabled_capabilities' => ['client_companion'],
            'disabled_providers' => ['openai'],
            'disabled_tools' => ['search_knowledge_base'],
            'max_tokens_per_run' => 2048,
            'max_daily_spend_minor_units' => 4000,
            'max_runs_per_minute' => 12,
            'max_tool_calls_per_run' => 2,
            'default_timeout_seconds' => 45,
            'max_failover_attempts' => 2,
        ]);

        $this->resolveFilamentContext($organization, $admin);
        $component = Livewire::actingAs($admin)->test(AiMonitoringOverview::class);
        $component->assertSet('data.max_daily_spend', '40.00');
        $component
            ->set('data.max_daily_spend', '50.00')
            ->call('save')
            ->assertHasNoErrors();

        $control->refresh();
        self::assertSame(5000, $control->max_daily_spend_minor_units);
        self::assertFalse($control->is_ai_globally_enabled);
        self::assertSame(['client_companion'], $control->disabled_capabilities);
        self::assertSame(['openai'], $control->disabled_providers);
        self::assertSame(['search_knowledge_base'], $control->disabled_tools);
        self::assertSame(2048, $control->max_tokens_per_run);
        self::assertSame(12, $control->max_runs_per_minute);
        self::assertSame(2, $control->max_tool_calls_per_run);
        self::assertSame(45, $control->default_timeout_seconds);
        self::assertSame(2, $control->max_failover_attempts);

        app(UpdateAiSafetyControl::class)->handle($admin, ['is_ai_globally_enabled' => true]);
        $budgetManager = app(AiSafetyBudgetManagerInterface::class);
        $budgetManager->reserveBudget($organization->getKey(), 5000);

        $this->expectException(AiBudgetExceededException::class);
        $budgetManager->reserveBudget($organization->getKey(), 1);
    }

    public function test_budget_conversion_rejects_malformed_negative_and_excess_precision_values(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $this->resolveFilamentContext($organization, $admin);

        foreach (['-1.00', '1.001', 'not money'] as $value) {
            try {
                app(UpdateAiSafetyControl::class)->handle($admin, ['max_daily_spend' => $value]);
                self::fail("Expected {$value} to be rejected.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $control = app(UpdateAiSafetyControl::class)->handle($admin, ['max_daily_spend' => '50.00']);
        self::assertSame(5000, $control->max_daily_spend_minor_units);
        self::assertSame('50.00', AiMoney::decimalFromMinorUnits($control->max_daily_spend_minor_units));
    }

    public function test_first_partial_budget_update_creates_a_complete_safe_control_for_the_current_organization(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $control = app(UpdateAiSafetyControl::class)->handle($admin, ['max_daily_spend' => '25.50']);

        self::assertSame($organization->getKey(), $control->organization_id);
        self::assertSame(2550, $control->max_daily_spend_minor_units);
        self::assertTrue($control->is_ai_globally_enabled);
        self::assertSame(8192, $control->max_tokens_per_run);
        self::assertSame(60, $control->max_runs_per_minute);
    }

    public function test_budget_update_uses_organization_context_and_does_not_touch_another_organization(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignControl = AiOrganizationSafetyControl::create([
            'organization_id' => $foreignOrganization->getKey(),
            'max_daily_spend_minor_units' => 1200,
        ]);

        app(UpdateAiSafetyControl::class)->handle($admin, ['max_daily_spend' => '33.00']);

        self::assertSame(3300, AiOrganizationSafetyControl::query()
            ->where('organization_id', $organization->getKey())
            ->value('max_daily_spend_minor_units'));
        self::assertSame(1200, $foreignControl->refresh()->max_daily_spend_minor_units);
    }

    public function test_model_pricing_is_exact_failover_order_is_preserved_and_old_release_snapshot_is_immutable(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'health_status' => ProviderHealthStatus::Healthy,
        ]);

        $model = app(CreateModelConfiguration::class)->handle($admin, $provider, [
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o Mini',
            'input_cost_per_million' => 250,
            'output_cost_per_million' => 1000,
            'cache_read_input_cost_per_million' => 25,
            'cache_write_input_cost_per_million' => 50,
            'reasoning_cost_per_million' => 125,
            'fixed_request_cost_minor_units' => 1,
            'capabilities' => [AiCapability::ClientCompanion->value],
            'failover_priority' => 2,
        ]);

        self::assertSame(250, $model->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $model->getPricingSnapshot()->outputCostPerMillionMinorUnits);
        self::assertSame(25, $model->getPricingSnapshot()->cacheReadInputCostPerMillionMinorUnits);
        self::assertSame(50, $model->getPricingSnapshot()->cacheWriteInputCostPerMillionMinorUnits);
        self::assertSame(125, $model->getPricingSnapshot()->reasoningCostPerMillionMinorUnits);
        self::assertSame(2, $model->failover_priority);

        $releaseOne = app(CreateAndActivateModelRelease::class)->handle($admin, $model, [
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o Mini',
            'input_cost_per_million' => 250,
            'output_cost_per_million' => 1000,
            'cache_read_input_cost_per_million' => 25,
            'cache_write_input_cost_per_million' => 50,
            'reasoning_cost_per_million' => 125,
            'fixed_request_cost_minor_units' => 1,
            'capabilities' => [AiCapability::ClientCompanion->value],
            'failover_priority' => 2,
            'is_enabled' => true,
        ]);

        $releaseTwo = app(CreateAndActivateModelRelease::class)->handle($admin, $model->refresh(), [
            'model_name' => 'gpt-4o-mini-2026',
            'display_name' => 'GPT-4o Mini 2026',
            'input_cost_per_million' => 375,
            'output_cost_per_million' => 1200,
            'cache_read_input_cost_per_million' => 30,
            'cache_write_input_cost_per_million' => 60,
            'reasoning_cost_per_million' => 150,
            'fixed_request_cost_minor_units' => 2,
            'capabilities' => [AiCapability::ClientCompanion->value],
            'failover_priority' => 1,
            'is_enabled' => true,
        ]);

        self::assertNotSame($releaseOne->getKey(), $releaseTwo->getKey());
        self::assertSame('2.50', AiMoney::decimalFromMinorUnits($releaseOne->refresh()->pricing_snapshot['input_cost_per_million_minor_units']));
        self::assertSame('3.75', AiMoney::decimalFromMinorUnits($releaseTwo->pricing_snapshot['input_cost_per_million_minor_units']));
        self::assertSame('gpt-4o-mini', $releaseOne->model_name);
        self::assertSame('gpt-4o-mini-2026', $releaseTwo->model_name);
        self::assertSame($releaseTwo->getKey(), $model->refresh()->active_release_id);
        self::assertSame([$model->getKey()], AiModelConfiguration::query()->orderBy('failover_priority')->pluck('id')->all());

        $releaseCount = $model->releases()->count();
        try {
            app(CreateAndActivateModelRelease::class)->handle($admin, $model->refresh(), [
                'pricing_snapshot' => [
                    'currency' => 'USD',
                    'input_cost_per_million_minor_units' => '2.50',
                    'output_cost_per_million_minor_units' => 1000,
                    'cache_read_input_cost_per_million_minor_units' => 25,
                    'cache_write_input_cost_per_million_minor_units' => 50,
                    'reasoning_cost_per_million_minor_units' => 125,
                    'fixed_request_cost_applicable' => false,
                    'fixed_request_cost_minor_units' => 0,
                    'unsupported_meters' => [],
                ],
            ]);
            self::fail('Malformed canonical pricing must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame($releaseCount, $model->releases()->count());
        }
    }

    public function test_model_relation_manager_uses_human_pricing_and_rehydrates_exact_values(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'health_status' => ProviderHealthStatus::Healthy,
        ]);
        $this->resolveFilamentContext($organization, $admin);

        Livewire::actingAs($admin)
            ->test(ModelsRelationManager::class, [
                'ownerRecord' => $provider,
                'pageClass' => EditAiProvider::class,
            ])
            ->mountTableAction('create')
            ->setTableActionData([
                'model_name' => 'gpt-4o-mini',
                'display_name' => 'GPT-4o Mini',
                'failover_priority' => 2,
                'input_cost_per_million' => '2.50',
                'output_cost_per_million' => '10.00',
                'cache_read_input_cost_per_million' => '0.25',
                'cache_write_input_cost_per_million' => '0.50',
                'reasoning_cost_per_million' => '1.25',
                'fixed_request_cost_minor_units' => '0.01',
                'fixed_request_cost_applicable' => true,
                'capabilities' => [AiCapability::ClientCompanion->value],
            ])
            ->callMountedTableAction();

        $model = AiModelConfiguration::query()->where('provider_config_id', $provider->getKey())->sole();
        self::assertSame(250, $model->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $model->getPricingSnapshot()->outputCostPerMillionMinorUnits);
        self::assertSame(25, $model->getPricingSnapshot()->cacheReadInputCostPerMillionMinorUnits);
        self::assertSame(50, $model->getPricingSnapshot()->cacheWriteInputCostPerMillionMinorUnits);
        self::assertSame(125, $model->getPricingSnapshot()->reasoningCostPerMillionMinorUnits);
        self::assertSame(1, $model->getPricingSnapshot()->fixedRequestCostMinorUnits);
        self::assertSame(2, $model->failover_priority);

        Livewire::actingAs($admin)
            ->test(ModelsRelationManager::class, [
                'ownerRecord' => $provider->refresh(),
                'pageClass' => EditAiProvider::class,
            ])
            ->mountTableAction('edit', $model)
            ->assertTableActionDataSet([
                'input_cost_per_million' => '2.50',
                'output_cost_per_million' => '10.00',
                'cache_read_input_cost_per_million' => '0.25',
                'fixed_request_cost_applicable' => true,
                'failover_priority' => 2,
            ]);
    }

    public function test_prompt_key_is_locked_and_new_version_preserves_advanced_metadata_and_generation_settings(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $prompt = app(CreateAiPrompt::class)->handle($admin, [
            'key' => 'client_companion_prompt',
            'name' => 'Client companion',
            'capability' => AiCapability::ClientCompanion->value,
            'description' => 'Human description',
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $organization->getKey(),
            'prompt_id' => $prompt->getKey(),
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'System v1',
            'user_prompt_template' => '{{query}}',
            'variables_schema' => ['query' => ['type' => 'string']],
            'parameter_config' => [
                'temperature' => 0.7,
                'top_p' => 0.8,
                'max_tokens' => 2048,
                'frequency_penalty' => 0.2,
                'presence_penalty' => 0.1,
                'timeout_seconds' => 45,
            ],
            'context_policy' => ['include_rag' => true],
            'output_schema' => ['type' => 'object'],
            'allowed_tools' => ['search_knowledge_base'],
            'change_notes' => 'Initial version',
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->getKey()]);

        $this->resolveFilamentContext($organization, $admin);
        Livewire::actingAs($admin)
            ->test(EditAiPrompt::class, ['record' => $prompt->getRouteKey()])
            ->assertFormFieldDisabled('key');

        Livewire::actingAs($admin)
            ->test(PromptVersionsRelationManager::class, [
                'ownerRecord' => $prompt->refresh(),
                'pageClass' => EditAiPrompt::class,
            ])
            ->mountTableAction('create')
            ->assertTableActionDataSet([
                'system_prompt' => 'System v1',
                'user_prompt_template' => '{{query}}',
                'temperature' => 0.7,
                'max_tokens' => 2048,
            ]);

        try {
            app(UpdateAiPrompt::class)->handle($admin, $prompt, ['key' => 'changed_key']);
            self::fail('A prompt key must be immutable.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot be changed', $exception->getMessage());
        }

        $newVersion = app(CreatePromptDraft::class)->handle($admin, $prompt->getKey(), [
            'system_prompt' => 'System v2',
            'user_prompt_template' => '{{query}} / {{context}}',
            'temperature' => '0.35',
            'max_tokens' => '3072',
            'change_notes' => 'Adjusted generation settings',
        ]);

        self::assertSame(2, $newVersion->version);
        self::assertSame(0.35, $newVersion->parameter_config['temperature']);
        self::assertSame(3072, $newVersion->parameter_config['max_tokens']);
        self::assertSame(0.8, $newVersion->parameter_config['top_p']);
        self::assertSame(0.2, $newVersion->parameter_config['frequency_penalty']);
        self::assertSame(['query' => ['type' => 'string']], $newVersion->variables_schema);
        self::assertSame(['include_rag' => true], $newVersion->context_policy);
        self::assertSame(['type' => 'object'], $newVersion->output_schema);
        self::assertSame(['search_knowledge_base'], $newVersion->allowed_tools);
        self::assertSame('active', $version->refresh()->status->value);
    }

    public function test_evaluation_key_and_linked_prompt_lookup_are_immutable_bounded_and_tenant_scoped(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $capability = AiCapability::ClientCompanion;
        for ($index = 1; $index <= 55; $index++) {
            AiPrompt::create([
                'organization_id' => $organization->getKey(),
                'key' => 'prompt_'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'name' => 'A Prompt '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'capability' => $capability,
            ]);
        }

        $target = AiPrompt::create([
            'organization_id' => $organization->getKey(),
            'key' => 'z_target_prompt',
            'name' => 'Z Target Prompt',
            'capability' => $capability,
        ]);
        $foreignOrganization = Organization::factory()->create();
        $foreign = AiPrompt::create([
            'organization_id' => $foreignOrganization->getKey(),
            'key' => 'z_foreign_prompt',
            'name' => 'Z Target Prompt',
            'capability' => $capability,
        ]);

        $this->resolveFilamentContext($organization, $admin);
        $component = Livewire::actingAs($admin)->test(CreateAiEvaluation::class);
        $component->fillForm(['capability' => $capability->value]);
        $select = $component->instance()->getSchemaComponent('form.prompt_id');

        self::assertInstanceOf(Select::class, $select);
        self::assertTrue($select->hasDynamicOptions());
        self::assertTrue($select->hasDynamicSearchResults());
        self::assertSame(50, $select->getOptionsLimit());
        $initialOptions = $select->getOptionsForJs();
        self::assertCount(50, $initialOptions);
        self::assertFalse(collect($initialOptions)->contains('value', (string) $target->getKey()));
        self::assertFalse(collect($initialOptions)->contains('value', (string) $foreign->getKey()));

        $searchResults = $component->instance()->callSchemaComponentMethod(
            'form.prompt_id',
            'getSearchResultsForJs',
            ['search' => 'Z Target Prompt'],
        );
        self::assertSame([
            ['label' => 'Z Target Prompt · z_target_prompt', 'value' => (string) $target->getKey(), 'isDisabled' => false],
        ], $searchResults);
        self::assertFalse(collect($searchResults)->contains('value', (string) $foreign->getKey()));

        $suite = app(CreateAiEvaluationSuite::class)->handle($admin, [
            'key' => 'client_companion_eval',
            'name' => 'Client companion tests',
            'capability' => $capability->value,
            'prompt_id' => $target->getKey(),
        ]);

        try {
            app(UpdateAiEvaluationSuite::class)->handle($admin, $suite, ['key' => 'changed_eval']);
            self::fail('An evaluation key must be immutable.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('cannot be changed', $exception->getMessage());
        }

        self::assertSame('client_companion_eval', $suite->refresh()->key);
    }

    public function test_cross_organization_prompt_and_evaluation_writes_are_rejected(): void
    {
        [$organization, $admin] = $this->organizationFixture();
        $foreignOrganization = Organization::factory()->create();
        $foreignPrompt = AiPrompt::create([
            'organization_id' => $foreignOrganization->getKey(),
            'key' => 'foreign_prompt',
            'name' => 'Foreign prompt',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $prompt = AiPrompt::create([
            'organization_id' => $organization->getKey(),
            'key' => 'local_prompt',
            'name' => 'Local prompt',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $suite = AiEvalSuite::create([
            'organization_id' => $organization->getKey(),
            'key' => 'local_suite',
            'name' => 'Local suite',
            'capability' => AiCapability::ClientCompanion,
        ]);

        try {
            app(CreateAiEvaluationSuite::class)->handle($admin, [
                'key' => 'foreign_link',
                'name' => 'Foreign link',
                'capability' => AiCapability::ClientCompanion->value,
                'prompt_id' => $foreignPrompt->getKey(),
            ]);
            self::fail('A foreign prompt must not be linked.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('current organization', $exception->getMessage());
        }

        try {
            app(UpdateAiPrompt::class)->handle($admin, $foreignPrompt, ['name' => 'Tampered']);
            self::fail('A foreign prompt must not be updated.');
        } catch (AuthorizationException) {
            self::assertSame('Foreign prompt', $foreignPrompt->refresh()->name);
        }

        self::assertSame($organization->getKey(), $suite->organization_id);
        self::assertSame($prompt->getKey(), $prompt->refresh()->getKey());
    }

    /** @return array{Organization, User} */
    private function organizationFixture(): array
    {
        $organization = Organization::factory()->create(['timezone' => 'UTC']);
        $admin = User::factory()->forOrganization($organization, OrganizationRole::Administrator)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $admin];
    }

    private function resolveFilamentContext(Organization $organization, User $admin): void
    {
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function credential(Organization $organization, string $provider, string $name): OrganizationCredential
    {
        $credential = new OrganizationCredential([
            'provider' => $provider,
            'credential_name' => $name,
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $organization->getKey();
        $credential->credentials = ['api_key' => 'sk-openai-secret'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        return $credential;
    }
}
