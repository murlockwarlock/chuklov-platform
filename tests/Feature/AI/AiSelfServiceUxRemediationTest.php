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
use App\Modules\AI\Application\Data\AiModelConfigurationInput;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiModelModality;
use App\Modules\AI\Domain\Exceptions\AiPricingProfileIncompleteException;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiModelCatalog;
use App\Modules\AI\Domain\Registry\AiModelDefinition;
use App\Modules\AI\Domain\ValueObjects\AiMoney;
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

        self::assertStringNotContainsString(
            'sk-owner-self-service-secret',
            serialize($component->instance()->form->getRawState()),
        );

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

        self::assertStringContainsString('Каталожная модель · Catalog family', AiModelCatalog::optionsForProvider('openai')['catalog-model']);
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

    public function test_manual_model_activation_requires_explicit_zero_for_unbilled_optional_meters(): void
    {
        [, $owner] = $this->organizationFixture();
        $provider = $this->provider('openai');
        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'manual-meter-model',
            'display_name' => 'Manual meter model',
            'capabilities' => [AiCapability::GeneralAssistant->value],
            'input_cost_per_million' => '0.30',
            'output_cost_per_million' => '1.00',
        ]);

        self::assertFalse($model->getPricingSnapshot()->isComplete());
        $this->expectException(AiPricingProfileIncompleteException::class);

        app(CreateAndActivateModelRelease::class)->handle($owner, $model, [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'manual-meter-model',
            'input_cost_per_million' => '0.30',
            'output_cost_per_million' => '1.00',
        ]);
    }

    public function test_manual_modality_payload_is_bounded_by_the_provider_adapter(): void
    {
        [, $owner] = $this->organizationFixture();
        $provider = $this->provider('deepseek');

        $this->expectException(InvalidArgumentException::class);

        app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'deepseek-manual-model',
            'display_name' => 'DeepSeek manual model',
            'capabilities' => [AiCapability::GeneralAssistant->value],
            'model_modalities' => [AiModelModality::DocumentInput->value],
            'input_cost_per_million' => '0.14',
            'output_cost_per_million' => '0.28',
            'cache_read_input_cost_per_million' => '0',
            'cache_write_input_cost_per_million' => '0',
            'reasoning_cost_per_million' => '0',
        ]);
    }

    public function test_explicit_custom_edit_does_not_inherit_catalog_price_or_modalities(): void
    {
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', [$this->catalogEntry('catalog-model', ['image_input'])]);
        $provider = $this->provider('openai');
        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => 'catalog-model',
            'display_name' => 'Каталожная модель',
            'capabilities' => [AiCapability::GeneralAssistant->value],
        ]);

        $release = app(CreateAndActivateModelRelease::class)->handle($owner, $model, [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'custom-model',
            'display_name' => 'Ручная модель',
            'capabilities' => [
                AiCapability::GeneralAssistant->value,
                AiModelModality::ImageInput->value,
            ],
            'model_modalities' => [],
            'input_cost_per_million' => '2.50',
            'output_cost_per_million' => '10.00',
            'cache_read_input_cost_per_million' => '0.25',
            'cache_write_input_cost_per_million' => '0.50',
            'reasoning_cost_per_million' => '1.25',
            'fixed_request_cost_applicable' => false,
            'unsupported_meters' => [],
            'is_enabled' => true,
        ]);

        self::assertSame(AiPricingSnapshot::SOURCE_MANUAL, $release->getPricingSnapshot()->pricingSource);
        self::assertSame('custom-model', $release->model_name);
        self::assertSame(['general_assistant'], $release->capabilities);
        self::assertSame(AiPricingSnapshot::SOURCE_MANUAL, $model->refresh()->getPricingSnapshot()->pricingSource);
    }

    public function test_custom_transition_does_not_reuse_manual_state_from_current_catalog_model(): void
    {
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', [$this->catalogEntry('catalog-model', ['image_input'])]);
        $provider = $this->provider('openai');
        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => 'catalog-model',
            'display_name' => 'Каталожная модель',
            'capabilities' => [AiCapability::GeneralAssistant->value],
        ]);

        app(CreateAndActivateModelRelease::class)->handle($owner, $model, [
            'model_selection' => 'catalog-model',
            'display_name' => 'Каталожная модель',
            'capabilities' => [
                AiCapability::GeneralAssistant->value,
                AiModelModality::ImageInput->value,
            ],
            'model_modalities' => [],
            'input_cost_per_million' => '3.00',
            'output_cost_per_million' => '11.00',
            'is_enabled' => true,
        ]);

        self::assertSame(AiPricingSnapshot::SOURCE_CATALOG, $model->refresh()->getPricingSnapshot()->pricingSource);
        self::assertSame(250, $model->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $model->getPricingSnapshot()->outputCostPerMillionMinorUnits);

        $custom = AiModelConfigurationInput::forRelease($model, [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'custom-model',
            'display_name' => 'Ручная модель',
            'capabilities' => [
                AiCapability::GeneralAssistant->value,
                AiModelModality::ImageInput->value,
            ],
            'model_modalities' => [],
        ]);

        self::assertSame('custom-model', $custom->modelName);
        self::assertSame(['general_assistant'], $custom->capabilities);
        self::assertSame(AiPricingSnapshot::SOURCE_UNKNOWN, $custom->pricing->pricingSource);
        self::assertFalse($custom->pricing->isComplete());
        self::assertNull($custom->pricing->catalogPricingEffectiveFrom);
        self::assertNull($custom->pricing->catalogPricingEffectiveUntil);
        self::assertNull($custom->pricing->catalogPricingAsOf);
    }

    public function test_custom_transition_to_catalog_uses_catalog_pricing_and_modalities(): void
    {
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', [$this->catalogEntry('catalog-model', ['image_input'])]);
        $provider = $this->provider('openai');
        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'custom-model',
            'display_name' => 'Ручная модель',
            'capabilities' => [AiCapability::GeneralAssistant->value],
            'model_modalities' => [AiModelModality::DocumentInput->value],
            'input_cost_per_million' => '1.00',
            'output_cost_per_million' => '2.00',
        ]);

        $release = app(CreateAndActivateModelRelease::class)->handle($owner, $model, [
            'model_selection' => 'catalog-model',
            'capabilities' => [
                AiCapability::GeneralAssistant->value,
                AiModelModality::DocumentInput->value,
            ],
            'model_modalities' => [],
            'is_enabled' => true,
        ]);

        self::assertSame('catalog-model', $release->model_name);
        self::assertSame(['general_assistant', 'image_input'], $release->capabilities);
        self::assertSame(AiPricingSnapshot::SOURCE_CATALOG, $release->getPricingSnapshot()->pricingSource);
        self::assertSame(250, $release->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $release->getPricingSnapshot()->outputCostPerMillionMinorUnits);
    }

    public function test_model_identity_transition_matrix_keeps_catalog_and_user_owned_state_separate(): void
    {
        [, $owner] = $this->organizationFixture();
        $guidedB = $this->catalogEntry('guided-b', [AiModelModality::DocumentInput->value]);
        $guidedB['pricing']['input_cost_per_million_minor_units'] = 700;
        $guidedB['pricing']['output_cost_per_million_minor_units'] = 1800;
        config()->set('ai.model_catalog', [
            $this->catalogEntry('guided-a', [AiModelModality::ImageInput->value]),
            $guidedB,
        ]);
        $provider = $this->provider('openai');

        $sourceModels = [
            'guided' => app(CreateModelConfiguration::class)->handle($owner, $provider, [
                'model_selection' => 'guided-a',
                'display_name' => 'Исходная guided',
                'capabilities' => [AiCapability::GeneralAssistant->value],
            ]),
            'custom' => app(CreateModelConfiguration::class)->handle($owner, $provider, [
                'model_selection' => AiModelCatalog::CUSTOM_MODEL,
                'model_name' => 'matrix-custom-a',
                'display_name' => 'Исходная custom',
                'capabilities' => [AiCapability::GeneralAssistant->value],
                'model_modalities' => [AiModelModality::DocumentInput->value],
                'input_cost_per_million' => '1.25',
                'output_cost_per_million' => '5.00',
                'cache_read_input_cost_per_million' => '0.10',
                'cache_write_input_cost_per_million' => '0.20',
                'reasoning_cost_per_million' => '0.30',
            ]),
            'legacy' => AiModelConfiguration::create([
                'organization_id' => app(OrganizationContext::class)->id(),
                'provider_config_id' => $provider->getKey(),
                'model_name' => 'matrix-legacy',
                'display_name' => 'Исходная legacy',
                'is_enabled' => false,
                'capabilities' => [
                    AiCapability::GeneralAssistant->value,
                    AiModelModality::DocumentInput->value,
                ],
                'pricing_snapshot' => (new AiPricingSnapshot(
                    inputCostPerMillionMinorUnits: 111,
                    outputCostPerMillionMinorUnits: 222,
                    cacheReadInputCostPerMillionMinorUnits: 1,
                    cacheWriteInputCostPerMillionMinorUnits: 2,
                    reasoningCostPerMillionMinorUnits: 3,
                ))->toArray(),
                'failover_priority' => 1,
            ]),
        ];

        $cases = [
            'guided_same' => [
                'source' => 'guided',
                'data' => [
                    'model_selection' => 'guided-a',
                    'model_modalities' => [AiModelModality::DocumentInput->value],
                    'input_cost_per_million' => '99.00',
                    'output_cost_per_million' => '199.00',
                    'pricing_snapshot' => [
                        'currency' => 'USD',
                        'input_cost_per_million_minor_units' => 9900,
                        'output_cost_per_million_minor_units' => 19900,
                        'cache_read_input_cost_per_million_minor_units' => 0,
                        'cache_write_input_cost_per_million_minor_units' => 0,
                        'reasoning_cost_per_million_minor_units' => 0,
                        'fixed_request_cost_applicable' => false,
                        'fixed_request_cost_minor_units' => 0,
                        'unsupported_meters' => [],
                    ],
                ],
                'model' => 'guided-a',
                'display' => 'Исходная guided',
                'capabilities' => [AiCapability::GeneralAssistant->value, AiModelModality::ImageInput->value],
                'pricing_source' => AiPricingSnapshot::SOURCE_CATALOG,
                'input' => 250,
                'output' => 1000,
            ],
            'guided_a_to_guided_b' => [
                'source' => 'guided',
                'data' => [
                    'model_selection' => 'guided-b',
                    'model_name' => 'forged-model-a',
                    'capabilities' => [AiCapability::GeneralAssistant->value, AiModelModality::ImageInput->value],
                    'model_modalities' => [AiModelModality::DocumentInput->value],
                    'input_cost_per_million' => '99.00',
                    'output_cost_per_million' => '199.00',
                    'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
                    'catalog_source' => 'forged-source',
                    'catalog_pricing_as_of' => '1900-01-01',
                    'lifecycle' => 'retired',
                ],
                'model' => 'guided-b',
                'display' => 'Исходная guided',
                'capabilities' => [AiCapability::GeneralAssistant->value, AiModelModality::DocumentInput->value],
                'pricing_source' => AiPricingSnapshot::SOURCE_CATALOG,
                'input' => 700,
                'output' => 1800,
            ],
            'guided_to_custom' => [
                'source' => 'guided',
                'data' => [
                    'model_selection' => AiModelCatalog::CUSTOM_MODEL,
                    'model_name' => 'matrix-custom-from-guided',
                    'capabilities' => [AiCapability::GeneralAssistant->value, AiModelModality::ImageInput->value],
                    'model_modalities' => [],
                    'fixed_request_cost_applicable' => false,
                    'unsupported_meters' => [],
                ],
                'model' => 'matrix-custom-from-guided',
                'display' => 'Исходная guided',
                'capabilities' => [AiCapability::GeneralAssistant->value],
                'pricing_source' => AiPricingSnapshot::SOURCE_UNKNOWN,
                'input' => 0,
                'output' => 0,
            ],
            'custom_same_partial' => [
                'source' => 'custom',
                'data' => [
                    'model_selection' => AiModelCatalog::CUSTOM_MODEL,
                    'model_name' => 'matrix-custom-a',
                    'display_name' => 'Изменённое custom',
                ],
                'model' => 'matrix-custom-a',
                'display' => 'Изменённое custom',
                'capabilities' => [AiCapability::GeneralAssistant->value, AiModelModality::DocumentInput->value],
                'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
                'input' => 125,
                'output' => 500,
            ],
            'custom_same_clear_modalities' => [
                'source' => 'custom',
                'data' => [
                    'model_selection' => AiModelCatalog::CUSTOM_MODEL,
                    'model_name' => 'matrix-custom-a',
                    'model_modalities' => [],
                ],
                'model' => 'matrix-custom-a',
                'display' => 'Исходная custom',
                'capabilities' => [AiCapability::GeneralAssistant->value],
                'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
                'input' => 125,
                'output' => 500,
            ],
            'custom_a_to_custom_b' => [
                'source' => 'custom',
                'data' => [
                    'model_selection' => AiModelCatalog::CUSTOM_MODEL,
                    'model_name' => 'matrix-custom-b',
                    'fixed_request_cost_applicable' => false,
                    'unsupported_meters' => [],
                ],
                'model' => 'matrix-custom-b',
                'display' => 'Исходная custom',
                'capabilities' => [AiCapability::GeneralAssistant->value],
                'pricing_source' => AiPricingSnapshot::SOURCE_UNKNOWN,
                'input' => 0,
                'output' => 0,
            ],
            'custom_a_to_custom_b_with_new_prices' => [
                'source' => 'custom',
                'data' => [
                    'model_selection' => AiModelCatalog::CUSTOM_MODEL,
                    'model_name' => 'matrix-custom-b',
                    'input_cost_per_million' => '9.00',
                    'output_cost_per_million' => '10.00',
                ],
                'model' => 'matrix-custom-b',
                'display' => 'Исходная custom',
                'capabilities' => [AiCapability::GeneralAssistant->value],
                'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
                'input' => 900,
                'output' => 1000,
            ],
            'custom_to_guided' => [
                'source' => 'custom',
                'data' => [
                    'model_selection' => 'guided-b',
                    'capabilities' => [AiCapability::GeneralAssistant->value, AiModelModality::DocumentInput->value],
                    'model_modalities' => [AiModelModality::ImageInput->value],
                    'input_cost_per_million' => '1.00',
                    'output_cost_per_million' => '2.00',
                    'catalog_source' => 'forged-source',
                ],
                'model' => 'guided-b',
                'display' => 'Исходная custom',
                'capabilities' => [AiCapability::GeneralAssistant->value, AiModelModality::DocumentInput->value],
                'pricing_source' => AiPricingSnapshot::SOURCE_CATALOG,
                'input' => 700,
                'output' => 1800,
            ],
            'legacy_same' => [
                'source' => 'legacy',
                'data' => [],
                'model' => 'matrix-legacy',
                'display' => 'Исходная legacy',
                'capabilities' => [AiCapability::GeneralAssistant->value, AiModelModality::DocumentInput->value],
                'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
                'input' => 111,
                'output' => 222,
            ],
            'legacy_to_custom' => [
                'source' => 'legacy',
                'data' => [
                    'model_selection' => AiModelCatalog::CUSTOM_MODEL,
                    'model_name' => 'matrix-custom-from-legacy',
                ],
                'model' => 'matrix-custom-from-legacy',
                'display' => 'Исходная legacy',
                'capabilities' => [AiCapability::GeneralAssistant->value],
                'pricing_source' => AiPricingSnapshot::SOURCE_UNKNOWN,
                'input' => 0,
                'output' => 0,
            ],
            'legacy_to_guided' => [
                'source' => 'legacy',
                'data' => ['model_selection' => 'guided-a'],
                'model' => 'guided-a',
                'display' => 'Исходная legacy',
                'capabilities' => [AiCapability::GeneralAssistant->value, AiModelModality::ImageInput->value],
                'pricing_source' => AiPricingSnapshot::SOURCE_CATALOG,
                'input' => 250,
                'output' => 1000,
            ],
        ];

        foreach ($cases as $case => $expectation) {
            $input = AiModelConfigurationInput::forRelease(
                $sourceModels[$expectation['source']],
                $expectation['data'],
            );

            self::assertSame($expectation['model'], $input->modelName, $case);
            self::assertSame($expectation['display'], $input->displayName, $case);
            self::assertSame($expectation['capabilities'], $input->capabilities, $case);
            self::assertSame($expectation['pricing_source'], $input->pricing->pricingSource, $case);
            self::assertSame($expectation['input'], $input->pricing->inputCostPerMillionMinorUnits, $case);
            self::assertSame($expectation['output'], $input->pricing->outputCostPerMillionMinorUnits, $case);
        }
    }

    public function test_partial_guided_release_rehydrates_current_catalog_state_after_stale_model_data(): void
    {
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', [$this->catalogEntry('guided-a', [AiModelModality::ImageInput->value])]);
        $provider = $this->provider('openai');
        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => 'guided-a',
            'display_name' => 'Пользовательское название',
            'capabilities' => [AiCapability::GeneralAssistant->value],
            'failover_priority' => 2,
            'is_enabled' => false,
        ]);
        $model->update([
            'capabilities' => [
                AiCapability::GeneralAssistant->value,
                AiModelModality::DocumentInput->value,
            ],
            'pricing_snapshot' => (new AiPricingSnapshot(
                inputCostPerMillionMinorUnits: 200,
                outputCostPerMillionMinorUnits: 900,
                cacheReadInputCostPerMillionMinorUnits: 20,
                cacheWriteInputCostPerMillionMinorUnits: 40,
                reasoningCostPerMillionMinorUnits: 0,
                pricingSource: AiPricingSnapshot::SOURCE_CATALOG,
            ))->toArray(),
        ]);

        $release = app(CreateAndActivateModelRelease::class)->handle($owner, $model->refresh(), []);

        self::assertSame('guided-a', $release->model_name);
        self::assertSame('Пользовательское название', $model->refresh()->display_name);
        self::assertSame(2, $model->failover_priority);
        self::assertFalse($model->is_enabled);
        self::assertSame([
            AiCapability::GeneralAssistant->value,
            AiModelModality::ImageInput->value,
        ], $release->capabilities);
        self::assertSame(AiPricingSnapshot::SOURCE_CATALOG, $release->getPricingSnapshot()->pricingSource);
        self::assertSame(250, $release->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $release->getPricingSnapshot()->outputCostPerMillionMinorUnits);
        self::assertSame('test_catalog', $release->getPricingSnapshot()->catalogSource);
    }

    public function test_model_configuration_input_rejects_forged_state_and_parses_money_once(): void
    {
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', [$this->catalogEntry('guided-a', [AiModelModality::ImageInput->value])]);
        $provider = $this->provider('openai');
        $base = [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'strict-custom-model',
            'display_name' => 'Строгая ручная модель',
            'capabilities' => [AiCapability::GeneralAssistant->value],
            'input_cost_per_million' => '2.50',
            'output_cost_per_million' => '10.00',
            'cache_read_input_cost_per_million' => '0.25',
            'cache_write_input_cost_per_million' => '0.50',
            'reasoning_cost_per_million' => '1.25',
            'fixed_request_cost_applicable' => false,
            'unsupported_meters' => [],
        ];

        $money = AiModelConfigurationInput::forCreate($provider, $base);
        self::assertSame(250, $money->pricing->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $money->pricing->outputCostPerMillionMinorUnits);
        self::assertSame(AiPricingSnapshot::SOURCE_MANUAL, $money->pricing->pricingSource);

        $invalidMoney = [
            '2.50',
            2.5,
            '1e2',
            '-1',
            true,
            [],
            new \stdClass,
            ' 1',
            '01',
        ];
        foreach (['input_cost_per_million_minor_units', 'fixed_request_cost_minor_units'] as $field) {
            foreach ($invalidMoney as $value) {
                $snapshot = [
                    'currency' => 'USD',
                    'input_cost_per_million_minor_units' => 0,
                    'output_cost_per_million_minor_units' => 0,
                    'cache_read_input_cost_per_million_minor_units' => 0,
                    'cache_write_input_cost_per_million_minor_units' => 0,
                    'reasoning_cost_per_million_minor_units' => 0,
                    'fixed_request_cost_applicable' => $field === 'fixed_request_cost_minor_units',
                    'fixed_request_cost_minor_units' => 0,
                    'unsupported_meters' => [],
                ];
                $snapshot[$field] = $value;

                try {
                    AiModelConfigurationInput::forCreate($provider, [
                        ...$base,
                        'pricing_snapshot' => $snapshot,
                    ]);
                    self::fail("Malformed canonical money was accepted for {$field}.");
                } catch (InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }

        $invalidPayloads = [
            ['capabilities', [['general_assistant']]],
            ['capabilities', ['not_a_chuklov_capability']],
            ['model_modalities', ['audio_input']],
            ['model_modalities', [['image_input']]],
            ['is_enabled', 'false'],
            ['fixed_request_cost_applicable', 'false'],
            ['unsupported_meters', [new \stdClass]],
        ];
        foreach ($invalidPayloads as [$field, $value]) {
            try {
                AiModelConfigurationInput::forCreate($provider, [
                    ...$base,
                    $field => $value,
                ]);
                self::fail("Forged {$field} was accepted.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $guided = AiModelConfigurationInput::forCreate($provider, [
            'model_selection' => 'guided-a',
            'model_name' => 'forged-model-name',
            'display_name' => 'Каталог',
            'capabilities' => [AiCapability::GeneralAssistant->value, AiModelModality::DocumentInput->value],
            'model_modalities' => [AiModelModality::DocumentInput->value],
            'pricing_source' => AiPricingSnapshot::SOURCE_MANUAL,
            'catalog_source' => 'forged-source',
            'catalog_pricing_as_of' => '1900-01-01',
            'lifecycle' => 'retired',
        ]);
        self::assertSame('guided-a', $guided->modelName);
        self::assertSame([AiCapability::GeneralAssistant->value, AiModelModality::ImageInput->value], $guided->capabilities);
        self::assertSame(AiPricingSnapshot::SOURCE_CATALOG, $guided->pricing->pricingSource);
        self::assertSame('test_catalog', $guided->pricing->catalogSource);
        self::assertNull($guided->pricing->catalogPricingAsOf);

        $custom = AiModelConfigurationInput::forCreate($provider, [
            ...$base,
            'pricing_source' => AiPricingSnapshot::SOURCE_CATALOG,
            'catalog_source' => 'forged-source',
            'catalog_pricing_as_of' => '1900-01-01',
        ]);
        self::assertSame(AiPricingSnapshot::SOURCE_MANUAL, $custom->pricing->pricingSource);
        self::assertNull($custom->pricing->catalogSource);
        self::assertNull($custom->pricing->catalogPricingAsOf);

        $retired = $this->catalogEntry('retired-model', []);
        $retired['lifecycle'] = 'retired';
        config()->set('ai.model_catalog', [$retired]);
        try {
            AiModelConfigurationInput::forCreate($provider, [
                'model_selection' => 'retired-model',
                'display_name' => 'Retired',
                'capabilities' => [AiCapability::GeneralAssistant->value],
            ]);
            self::fail('A retired catalog model was accepted for a new configuration.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }

        config()->set('ai.model_catalog', [$this->catalogEntry('guided-a', [])]);
        try {
            AiModelConfigurationInput::forCreate($provider, [
                'model_selection' => AiModelCatalog::CUSTOM_MODEL,
                'model_name' => 'guided-a',
                'display_name' => 'Collision',
                'capabilities' => [AiCapability::GeneralAssistant->value],
            ]);
            self::fail('A custom identity colliding with the catalog was accepted.');
        } catch (InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function test_partial_release_update_preserves_disabled_state_and_historical_release_data(): void
    {
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', []);
        $provider = $this->provider('openai');
        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'immutable-custom-model',
            'display_name' => 'Immutable custom',
            'capabilities' => [AiCapability::GeneralAssistant->value],
            'input_cost_per_million' => '1.25',
            'output_cost_per_million' => '5.00',
            'cache_read_input_cost_per_million' => '0.10',
            'cache_write_input_cost_per_million' => '0.20',
            'reasoning_cost_per_million' => '0.30',
            'is_enabled' => false,
        ]);

        $releaseOne = app(CreateAndActivateModelRelease::class)->handle($owner, $model, [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'immutable-custom-model',
            'is_enabled' => false,
        ]);
        $oldSnapshot = $releaseOne->getPricingSnapshot()->toArray();
        $oldCapabilities = $releaseOne->capabilities;
        $oldModelName = $releaseOne->model_name;

        $releaseTwo = app(CreateAndActivateModelRelease::class)->handle($owner, $model->refresh(), []);

        self::assertNotSame($releaseOne->getKey(), $releaseTwo->getKey());
        self::assertFalse($model->refresh()->is_enabled);
        self::assertSame($oldSnapshot, $releaseOne->refresh()->getPricingSnapshot()->toArray());
        self::assertSame($oldCapabilities, $releaseOne->capabilities);
        self::assertSame($oldModelName, $releaseOne->model_name);
        self::assertSame($oldSnapshot, $releaseTwo->getPricingSnapshot()->toArray());
    }

    public function test_default_catalog_contains_only_current_priced_choices_and_excludes_legacy_models(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 22, 12, 0, 0, 'UTC'));
        config()->set('ai.model_catalog', $this->defaultCatalog());

        $definitions = AiModelCatalog::all();

        self::assertSame([
            'gpt-5.6-terra',
            'gpt-5.6-sol',
            'gpt-5.6-luna',
            'claude-fable-5',
            'claude-opus-5',
            'claude-sonnet-5',
            'claude-haiku-4-5-20251001',
            'gemini-3.7-flash',
            'gemini-3.6-flash',
            'gemini-3.5-flash',
            'gemini-3.5-flash-lite',
            'gemini-3.1-flash-lite',
            'deepseek-v4-pro',
            'deepseek-v4-flash',
            'mistral-medium-3-5',
            'mistral-large-2512',
            'mistral-small-2603',
            'ministral-14b-2512',
            'ministral-8b-2512',
            'openai/gpt-oss-120b',
            'openai/gpt-oss-20b',
            'grok-4.6',
            'grok-4.3',
        ], array_map(
            static fn (AiModelDefinition $definition): string => $definition->modelName,
            $definitions,
        ));

        foreach ($definitions as $definition) {
            self::assertSame(
                $definition->modelName === 'gemini-3.1-flash-lite' ? 'deprecated' : 'active',
                $definition->lifecycleStatus->value,
            );
            self::assertNotNull($definition->pricing);
            self::assertSame(AiPricingSnapshot::SOURCE_CATALOG, $definition->pricing->pricingSource);
            self::assertSame(
                $definition->provider === 'openai' ? '2026-08-23' : '2026-08-22',
                $definition->pricingAsOf,
            );
            self::assertNotSame('', $definition->catalogSource);
        }

        self::assertArrayNotHasKey('gpt-4o-mini', AiModelCatalog::optionsForProvider('openai'));
        self::assertArrayNotHasKey('gpt-4o', AiModelCatalog::optionsForProvider('openai'));
        self::assertArrayNotHasKey('claude-3-5-sonnet', AiModelCatalog::optionsForProvider('anthropic'));
        self::assertArrayNotHasKey('gemini-2.0-flash', AiModelCatalog::optionsForProvider('gemini'));
    }

    public function test_switching_between_known_and_custom_models_clears_catalog_metadata_but_preserves_user_fields(): void
    {
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', [$this->catalogEntry('catalog-model', ['image_input'])]);
        $provider = $this->provider('openai');
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($owner)
            ->test(ModelsRelationManager::class, [
                'ownerRecord' => $provider,
                'pageClass' => EditAiProvider::class,
            ])
            ->mountTableAction('create')
            ->setTableActionData([
                'model_selection' => 'catalog-model',
                'capabilities' => [AiCapability::GeneralAssistant->value],
            ])
            ->setTableActionData([
                'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            ])
            ->assertTableActionDataSet([
                'model_selection' => AiModelCatalog::CUSTOM_MODEL,
                'model_name' => null,
                'display_name' => 'Каталожная модель',
                'input_cost_per_million' => null,
                'output_cost_per_million' => null,
                'cache_read_input_cost_per_million' => null,
                'cache_write_input_cost_per_million' => null,
                'reasoning_cost_per_million' => null,
                'fixed_request_cost_applicable' => false,
                'fixed_request_cost_minor_units' => null,
                'unsupported_meters' => null,
                'model_modalities' => [],
            ]);
    }

    public function test_known_catalog_models_are_priced_without_manual_token_entry(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 8, 22, 12, 0, 0, 'UTC'));
        [, $owner] = $this->organizationFixture();
        config()->set('ai.model_catalog', $this->defaultCatalog());

        $expected = [
            ['openai', 'gpt-5.6-terra', '2.000000', '12.000000', '0.200000', '2.500000'],
            ['anthropic', 'claude-sonnet-5', '2.000000', '10.000000', '0.200000', '2.500000'],
            ['gemini', 'gemini-3.7-flash', '0.750000', '3.750000', '0.075000', null],
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
            self::assertSame($input, AiMoney::decimalFromRateUnits($pricing->inputRatePerMillionUnits()));
            self::assertSame($output, AiMoney::decimalFromRateUnits($pricing->outputRatePerMillionUnits()));
            self::assertSame($cacheRead, $pricing->cacheReadRatePerMillionUnits() === null
                ? null
                : AiMoney::decimalFromRateUnits($pricing->cacheReadRatePerMillionUnits()));
            self::assertSame($cacheWrite, $pricing->cacheWriteRatePerMillionUnits() === null
                ? null
                : AiMoney::decimalFromRateUnits($pricing->cacheWriteRatePerMillionUnits()));
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
            'capabilities' => [
                AiCapability::GeneralAssistant->value,
                AiModelModality::DocumentInput->value,
            ],
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
                'model_modalities' => [AiModelModality::DocumentInput->value],
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
            'catalog_source' => 'test_catalog',
        ];
    }
}
