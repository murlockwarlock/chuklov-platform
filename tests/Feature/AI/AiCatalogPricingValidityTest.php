<?php

namespace Tests\Feature\AI;

use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Filament\Resources\AiProviders\RelationManagers\ModelsRelationManager;
use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\CreateModelConfiguration;
use App\Modules\AI\Application\Actions\PrepareAiRun;
use App\Modules\AI\Application\Actions\ResolveAiExecutionCandidates;
use App\Modules\AI\Application\Data\AiRunRequest;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiExecutionMode;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiOrganizationDailyBudget;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiModelCatalog;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

final class AiCatalogPricingValidityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_sonnet_current_standard_pricing_resolves_without_a_retirement_transition(): void
    {
        config()->set('ai.model_catalog', $this->defaultCatalog());

        $this->at('2026-08-22 00:00:00');
        $current = AiModelCatalog::find('anthropic', 'claude-sonnet-5');
        self::assertNotNull($current);
        self::assertNotNull($current->pricing);
        self::assertSame(200, $current->pricing->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $current->pricing->outputCostPerMillionMinorUnits);
        self::assertSame(20, $current->pricing->cacheReadInputCostPerMillionMinorUnits);
        self::assertSame(400, $current->pricing->cacheWriteInputCostPerMillionMinorUnits);
        self::assertNull($current->pricing->catalogPricingEffectiveFrom);
        self::assertNull($current->pricing->catalogPricingEffectiveUntil);
        self::assertSame('2026-08-22', $current->pricingAsOf);

        $this->at('2026-09-01 00:00:00');
        $unchanged = AiModelCatalog::find('anthropic', 'claude-sonnet-5');
        self::assertNotNull($unchanged);
        self::assertNotNull($unchanged->pricing);
        self::assertSame($current->pricing->toArray(), $unchanged->pricing->toArray());
    }

    public function test_invalid_and_overlapping_catalog_periods_are_rejected(): void
    {
        $invalid = $this->catalogEntryWithPeriods([
            [
                'effective_from' => '2026-02-30 00:00:00',
                'effective_until' => null,
                'pricing' => $this->pricing(200, 1000),
            ],
        ]);
        config()->set('ai.model_catalog', [$invalid]);

        $this->expectException(InvalidArgumentException::class);
        AiModelCatalog::all();
    }

    public function test_reversed_catalog_periods_are_rejected(): void
    {
        config()->set('ai.model_catalog', [$this->catalogEntryWithPeriods([
            [
                'effective_from' => '2026-09-02 00:00:00',
                'effective_until' => '2026-09-01 23:59:59',
                'pricing' => $this->pricing(200, 1000),
            ],
        ])]);

        $this->expectException(InvalidArgumentException::class);
        AiModelCatalog::all();
    }

    public function test_catalog_period_cannot_turn_missing_primary_prices_into_zero(): void
    {
        config()->set('ai.model_catalog', [$this->catalogEntryWithPeriods([
            [
                'effective_from' => '2026-08-22 00:00:00',
                'effective_until' => null,
                'pricing' => [
                    'currency' => 'USD',
                    'output_cost_per_million_minor_units' => 1000,
                ],
            ],
        ])]);

        $this->expectException(InvalidArgumentException::class);
        AiModelCatalog::all();
    }

    public function test_catalog_rejects_malformed_canonical_money_instead_of_casting_it(): void
    {
        config()->set('ai.model_catalog', [$this->catalogEntryWithPeriods([
            [
                'effective_from' => '2026-08-22 00:00:00',
                'effective_until' => null,
                'pricing' => [
                    'currency' => 'USD',
                    'input_cost_per_million_minor_units' => '2.50',
                    'output_cost_per_million_minor_units' => 1000,
                ],
            ],
        ])]);

        $this->expectException(InvalidArgumentException::class);
        AiModelCatalog::all();
    }

    public function test_overlapping_periods_are_rejected_even_when_the_boundary_is_shared(): void
    {
        config()->set('ai.model_catalog', [$this->catalogEntryWithPeriods([
            [
                'effective_from' => '2026-01-01 00:00:00',
                'effective_until' => '2026-01-31 23:59:59',
                'pricing' => $this->pricing(200, 1000),
            ],
            [
                'effective_from' => '2026-01-31 23:59:59',
                'effective_until' => null,
                'pricing' => $this->pricing(300, 1500),
            ],
        ])]);

        $this->expectException(InvalidArgumentException::class);
        AiModelCatalog::all();
    }

    public function test_catalog_without_a_current_pricing_period_is_unknown_not_zero(): void
    {
        config()->set('ai.model_catalog', [$this->catalogEntryWithPeriods([
            [
                'effective_from' => '2027-01-01 00:00:00',
                'effective_until' => null,
                'pricing' => $this->pricing(200, 1000),
            ],
        ])]);

        $definition = AiModelCatalog::find('openai', 'period-model');

        self::assertNotNull($definition);
        self::assertNull($definition->pricing);
        self::assertSame('Стоимость не задана', AiModelCatalog::pricingText($definition->pricing));
    }

    public function test_known_catalog_model_can_be_configured_without_manual_pricing(): void
    {
        $this->at('2026-09-01 00:00:00');
        config()->set('ai.model_catalog', $this->defaultCatalog());
        [$organization, $owner] = $this->organizationFixture();
        $provider = $this->provider('anthropic');

        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => 'claude-sonnet-5',
            'display_name' => 'Основная модель',
            'capabilities' => [AiCapability::GeneralAssistant->value],
        ]);

        self::assertSame($organization->getKey(), $model->organization_id);
        self::assertSame(AiPricingSnapshot::SOURCE_CATALOG, $model->getPricingSnapshot()->pricingSource);
        self::assertSame(200, $model->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $model->getPricingSnapshot()->outputCostPerMillionMinorUnits);

        $release = app(CreateAndActivateModelRelease::class)->handle($owner, $model, [
            'model_selection' => 'claude-sonnet-5',
            'is_enabled' => true,
        ]);

        self::assertSame(200, $release->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $release->getPricingSnapshot()->outputCostPerMillionMinorUnits);
    }

    public function test_old_release_remains_immutable_after_the_catalog_period_changes(): void
    {
        [$model, $release] = $this->sonnetRelease();
        $oldSnapshot = $release->getPricingSnapshot()->toArray();

        $this->at('2026-09-01 00:00:00');
        $release->refresh();
        $model->refresh();

        self::assertSame($oldSnapshot, $release->getPricingSnapshot()->toArray());
        self::assertSame($release->getKey(), $model->active_release_id);
        self::assertSame(200, $release->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $release->getPricingSnapshot()->outputCostPerMillionMinorUnits);
    }

    public function test_expired_catalog_release_is_excluded_before_new_budget_reservation(): void
    {
        [$model, $release] = $this->sonnetRelease(withCredential: true);
        $this->at('2026-08-31 23:59:59');
        $request = new AiRunRequest(
            capability: AiCapability::GeneralAssistant,
            workflowKey: 'catalog-pricing-validity',
            executionMode: AiExecutionMode::Async,
        );

        $validSnapshot = app(ResolveAiExecutionCandidates::class)->snapshot(
            organizationId: $model->organization_id,
            request: $request,
            safetyControls: null,
        );
        self::assertCount(1, $validSnapshot);
        self::assertSame(200, $validSnapshot[0]['pricing_snapshot']['input_cost_per_million_minor_units']);

        $this->at('2026-09-01 00:00:00');
        self::assertTrue(AiModelCatalog::pricingIsStale(
            'anthropic',
            'claude-sonnet-5',
            $release->getPricingSnapshot(),
        ));

        $staleSnapshot = app(ResolveAiExecutionCandidates::class)->snapshot(
            organizationId: $model->organization_id,
            request: $request,
            safetyControls: null,
        );
        self::assertSame([], $staleSnapshot);

        $prompt = AiPrompt::create([
            'organization_id' => $model->organization_id,
            'key' => 'catalog_pricing_validity_prompt',
            'name' => 'Catalog pricing validity prompt',
            'capability' => AiCapability::GeneralAssistant,
        ]);
        $promptVersion = AiPromptVersion::create([
            'organization_id' => $model->organization_id,
            'prompt_id' => $prompt->getKey(),
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Use the supplied context.',
            'user_prompt_template' => '{{query}}',
            'context_policy' => (new AiContextPolicy)->toArray(),
            'activated_at' => now(),
        ]);

        $claim = app(PrepareAiRun::class)->claim(
            organizationId: $model->organization_id,
            request: $request,
            promptVersion: $promptVersion,
            contextPolicy: new AiContextPolicy,
            executionDeadlineAt: CarbonImmutable::now()->addMinute(),
            maxToolCalls: 0,
        );

        self::assertSame([], $claim['run']->execution_candidate_snapshot);
        self::assertFalse(AiOrganizationDailyBudget::query()
            ->where('organization_id', $model->organization_id)
            ->exists());
    }

    public function test_catalog_snapshot_missing_or_changed_provenance_fails_closed(): void
    {
        config()->set('ai.model_catalog', $this->defaultCatalog());
        $definition = AiModelCatalog::find('openai', 'gpt-5.6-terra');
        self::assertNotNull($definition);
        self::assertNotNull($definition->pricing);

        $withoutMetadata = new AiPricingSnapshot(
            inputCostPerMillionMinorUnits: $definition->pricing->inputCostPerMillionMinorUnits,
            outputCostPerMillionMinorUnits: $definition->pricing->outputCostPerMillionMinorUnits,
            cacheReadInputCostPerMillionMinorUnits: $definition->pricing->cacheReadInputCostPerMillionMinorUnits,
            cacheWriteInputCostPerMillionMinorUnits: $definition->pricing->cacheWriteInputCostPerMillionMinorUnits,
            reasoningCostPerMillionMinorUnits: $definition->pricing->reasoningCostPerMillionMinorUnits,
            pricingSource: AiPricingSnapshot::SOURCE_CATALOG,
        );
        self::assertTrue(AiModelCatalog::pricingIsStale('openai', 'gpt-5.6-terra', $withoutMetadata));

        $withForgedSource = new AiPricingSnapshot(
            inputCostPerMillionMinorUnits: $definition->pricing->inputCostPerMillionMinorUnits,
            outputCostPerMillionMinorUnits: $definition->pricing->outputCostPerMillionMinorUnits,
            cacheReadInputCostPerMillionMinorUnits: $definition->pricing->cacheReadInputCostPerMillionMinorUnits,
            cacheWriteInputCostPerMillionMinorUnits: $definition->pricing->cacheWriteInputCostPerMillionMinorUnits,
            reasoningCostPerMillionMinorUnits: $definition->pricing->reasoningCostPerMillionMinorUnits,
            pricingSource: AiPricingSnapshot::SOURCE_CATALOG,
            catalogPricingAsOf: $definition->pricing->catalogPricingAsOf,
            catalogSource: 'forged-source',
        );
        self::assertTrue(AiModelCatalog::pricingIsStale('openai', 'gpt-5.6-terra', $withForgedSource));
    }

    public function test_practitioner_can_apply_the_current_catalog_price_as_a_new_release(): void
    {
        [$model, $oldRelease, $provider, $owner] = $this->sonnetRelease();
        $this->at('2026-09-01 00:00:00');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($owner);

        /** @var Testable<ModelsRelationManager> $component */
        $component = Livewire::actingAs($owner)->test(new ModelsRelationManager, [
            'ownerRecord' => $provider,
            'pageClass' => EditAiProvider::class,
        ]);
        $component
            ->loadTable()
            ->assertTableActionVisible('refresh_pricing', $model)
            ->assertSee('Стоимость модели обновилась')
            ->mountTableAction('refresh_pricing', $model)
            ->callMountedTableAction()
            ->assertHasNoErrors();

        $model->refresh();
        $newRelease = AiModelRelease::query()
            ->where('model_config_id', $model->getKey())
            ->orderByDesc('release_number')
            ->firstOrFail();

        self::assertNotSame($oldRelease->getKey(), $newRelease->getKey());
        self::assertSame($newRelease->getKey(), $model->active_release_id);
        self::assertSame(200, $oldRelease->refresh()->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $oldRelease->getPricingSnapshot()->outputCostPerMillionMinorUnits);
        self::assertSame(300, $newRelease->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1500, $newRelease->getPricingSnapshot()->outputCostPerMillionMinorUnits);
        self::assertSame('active', $newRelease->status);
        self::assertSame('retired', $oldRelease->status);
        self::assertSame(2, $newRelease->release_number);
    }

    public function test_unchanged_stale_catalog_form_values_cannot_be_saved_as_an_old_manual_price(): void
    {
        [$model, $oldRelease, , $owner] = $this->sonnetRelease();
        $this->at('2026-09-01 00:00:00');

        $newRelease = app(CreateAndActivateModelRelease::class)->handle($owner, $model, [
            'model_selection' => 'claude-sonnet-5',
            'display_name' => 'Claude Sonnet 5',
            'input_cost_per_million' => '2.00',
            'output_cost_per_million' => '10.00',
            'cache_read_input_cost_per_million' => '0.20',
            'cache_write_input_cost_per_million' => null,
            'reasoning_cost_per_million' => null,
            'fixed_request_cost_applicable' => false,
            'unsupported_meters' => [],
            'is_enabled' => true,
        ]);

        self::assertSame(AiPricingSnapshot::SOURCE_CATALOG, $newRelease->getPricingSnapshot()->pricingSource);
        self::assertSame(300, $newRelease->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1500, $newRelease->getPricingSnapshot()->outputCostPerMillionMinorUnits);
        self::assertSame(200, $oldRelease->refresh()->getPricingSnapshot()->inputCostPerMillionMinorUnits);
        self::assertSame(1000, $oldRelease->getPricingSnapshot()->outputCostPerMillionMinorUnits);
    }

    public function test_manual_custom_pricing_is_not_expired_by_catalog_periods(): void
    {
        $this->at('2026-08-22 12:00:00');
        config()->set('ai.model_catalog', $this->defaultCatalog());
        [$organization, $owner] = $this->organizationFixture();
        $provider = $this->providerWithCredential('openai');
        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'future-custom-model',
            'display_name' => 'Ручная модель',
            'capabilities' => [AiCapability::GeneralAssistant->value],
            'input_cost_per_million' => '1.25',
            'output_cost_per_million' => '2.50',
            'cache_read_input_cost_per_million' => '0.00',
            'cache_write_input_cost_per_million' => '0.00',
            'reasoning_cost_per_million' => '0.00',
        ]);
        $release = app(CreateAndActivateModelRelease::class)->handle($owner, $model, [
            'model_selection' => AiModelCatalog::CUSTOM_MODEL,
            'model_name' => 'future-custom-model',
            'is_enabled' => true,
        ]);

        $this->at('2026-09-01 00:00:00');
        self::assertSame(AiPricingSnapshot::SOURCE_MANUAL, $release->getPricingSnapshot()->pricingSource);
        self::assertFalse(AiModelCatalog::pricingIsStale(
            'openai',
            'future-custom-model',
            $release->getPricingSnapshot(),
        ));
        self::assertSame($organization->getKey(), $release->organization_id);
    }

    public function test_terra_and_gemini_non_expiring_catalog_prices_continue_to_resolve(): void
    {
        $this->at('2026-09-01 00:00:00');
        config()->set('ai.model_catalog', $this->defaultCatalog());

        $terra = AiModelCatalog::find('openai', 'gpt-5.6-terra');
        $gemini = AiModelCatalog::find('gemini', 'gemini-2.5-flash');

        self::assertNotNull($terra);
        self::assertNotNull($terra->pricing);
        self::assertNotNull($gemini);
        self::assertNotNull($gemini->pricing);
        self::assertSame(200, $terra->pricing->inputCostPerMillionMinorUnits);
        self::assertSame(1200, $terra->pricing->outputCostPerMillionMinorUnits);
        self::assertSame(20, $terra->pricing->cacheReadInputCostPerMillionMinorUnits);
        self::assertSame(250, $terra->pricing->cacheWriteInputCostPerMillionMinorUnits);
        self::assertSame(30, $gemini->pricing->inputCostPerMillionMinorUnits);
        self::assertSame(250, $gemini->pricing->outputCostPerMillionMinorUnits);
        self::assertSame(3, $gemini->pricing->cacheReadInputCostPerMillionMinorUnits);
        self::assertContains('document_input', array_map(
            static fn (\BackedEnum $modality): string => $modality->value,
            $gemini->modalities,
        ));
        $anthropic = AiModelCatalog::find('anthropic', 'claude-sonnet-5');
        self::assertNotNull($anthropic);
        self::assertContains('document_input', array_map(
            static fn (\BackedEnum $modality): string => $modality->value,
            $anthropic->modalities,
        ));
        self::assertFalse(AiModelCatalog::pricingIsStale('openai', 'gpt-5.6-terra', $terra->pricing));
        self::assertFalse(AiModelCatalog::pricingIsStale('gemini', 'gemini-2.5-flash', $gemini->pricing));
    }

    /** @return array{0: AiModelConfiguration, 1: AiModelRelease, 2: AiProviderConfiguration, 3: User} */
    private function sonnetRelease(bool $withCredential = false): array
    {
        $this->at('2026-08-22 12:00:00');
        config()->set('ai.model_catalog', $this->transitionCatalog());
        [, $owner] = $this->organizationFixture();
        $provider = $withCredential
            ? $this->providerWithCredential('anthropic')
            : $this->provider('anthropic');
        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => 'claude-sonnet-5',
            'display_name' => 'Claude Sonnet 5',
            'capabilities' => [AiCapability::GeneralAssistant->value],
        ]);
        $release = app(CreateAndActivateModelRelease::class)->handle($owner, $model, [
            'model_selection' => 'claude-sonnet-5',
            'is_enabled' => true,
        ]);

        return [$model, $release, $provider, $owner];
    }

    /** @return array{Organization, User} */
    private function organizationFixture(): array
    {
        $organization = Organization::query()->create([
            'name' => 'AI Pricing Clinic',
            'slug' => 'ai-pricing-clinic',
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

    private function providerWithCredential(string $providerName): AiProviderConfiguration
    {
        $organizationId = app(OrganizationContext::class)->id();
        if ($organizationId < 1) {
            throw new LogicException('An organization is required for the credential fixture.');
        }
        $credential = new OrganizationCredential([
            'provider' => $providerName,
            'credential_name' => $providerName.' test key',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $organizationId;
        $credential->credentials = ['api_key' => 'pricing-test-key'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        return AiProviderConfiguration::create([
            'organization_id' => $organizationId,
            'provider_name' => $providerName,
            'display_name' => ucfirst($providerName),
            'is_enabled' => true,
            'health_status' => ProviderHealthStatus::Healthy,
            'credential_id' => $credential->getKey(),
            'tested_credential_revision' => $credential->revision_id,
            'tested_configuration_digest' => AiProviderExecutionConfiguration::digest($providerName),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function defaultCatalog(): array
    {
        /** @var array{model_catalog: list<array<string, mixed>>} $config */
        $config = require base_path('config/ai.php');

        return $config['model_catalog'];
    }

    /** @return list<array<string, mixed>> */
    private function transitionCatalog(): array
    {
        return array_map(function (array $entry): array {
            if ($entry['provider'] !== 'anthropic' || $entry['model'] !== 'claude-sonnet-5') {
                return $entry;
            }

            unset($entry['pricing']);
            $entry['pricing_periods'] = [
                [
                    'effective_from' => '2026-08-22 00:00:00',
                    'effective_until' => '2026-08-31 23:59:59',
                    'pricing' => $this->pricing(200, 1000),
                    'pricing_as_of' => '2026-08-22',
                ],
                [
                    'effective_from' => '2026-09-01 00:00:00',
                    'effective_until' => null,
                    'pricing' => $this->pricing(300, 1500),
                    'pricing_as_of' => '2026-09-01',
                ],
            ];

            return $entry;
        }, $this->defaultCatalog());
    }

    /**
     * @param  list<array<string, mixed>>  $periods
     * @return array<string, mixed>
     */
    private function catalogEntryWithPeriods(array $periods): array
    {
        return [
            'provider' => 'openai',
            'model' => 'period-model',
            'display_name' => 'Period model',
            'family' => 'Period family',
            'supported_capabilities' => ['text_generation'],
            'modalities' => [],
            'pricing_periods' => $periods,
            'lifecycle' => 'active',
            'catalog_source' => 'test_catalog',
        ];
    }

    /** @return array<string, mixed> */
    private function pricing(int $input, int $output): array
    {
        return [
            'currency' => 'USD',
            'input_cost_per_million_minor_units' => $input,
            'output_cost_per_million_minor_units' => $output,
            'cache_read_input_cost_per_million_minor_units' => 20,
            'cache_write_input_cost_per_million_minor_units' => 400,
            'reasoning_cost_per_million_minor_units' => 0,
            'fixed_request_cost_applicable' => false,
            'unsupported_meters' => [],
        ];
    }

    private function at(string $value): CarbonImmutable
    {
        $now = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $value, 'UTC');
        if ($now === null) {
            throw new LogicException('The test clock could not be configured.');
        }
        CarbonImmutable::setTestNow($now);

        return $now;
    }
}
