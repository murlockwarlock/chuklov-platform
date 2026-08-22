<?php

namespace Tests\Feature\AI;

use App\Models\User;
use App\Modules\AI\Application\Actions\CreateAndActivateModelRelease;
use App\Modules\AI\Application\Actions\CreateModelConfiguration;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Registry\AiModelCatalog;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\ModelDiscovery\AiModelDiscoveryService;
use App\Modules\Knowledge\Domain\Registry\KnowledgeModelCatalog;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiModelDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_openrouter_discovery_filters_inactive_models_and_creates_a_guided_release(): void
    {
        [$organization, $owner] = $this->organizationFixture('discovery-clinic');
        $provider = $this->providerWithCredential($organization, 'openrouter');
        Cache::flush();
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [
                    [
                        'id' => 'acme/vision-production',
                        'name' => 'Acme Vision Production',
                        'canonical_slug' => 'acme/vision-production',
                        'description' => 'A current production model with a deliberately long but bounded description for the human picker.',
                        'context_length' => 131072,
                        'architecture' => [
                            'input_modalities' => ['text', 'image', 'file'],
                            'output_modalities' => ['text'],
                        ],
                        'supported_parameters' => ['response_format'],
                        'pricing' => [
                            'prompt' => '0.00000025',
                            'completion' => '0.00000125',
                            'input_cache_read' => '0.000000025',
                            'input_cache_write' => '0.0000005',
                        ],
                    ],
                    [
                        'id' => 'acme/preview-model',
                        'name' => 'Preview model',
                        'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
                        'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
                    ],
                    [
                        'id' => 'acme/retired-model',
                        'name' => 'Retired model',
                        'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
                        'pricing' => ['prompt' => '0.000001', 'completion' => '0.000002'],
                        'expiration_date' => '2025-01-01T00:00:00Z',
                    ],
                ],
            ], 200),
        ]);

        $result = app(AiModelDiscoveryService::class)->discover($provider->load('credential'));
        $models = $result->models();

        self::assertCount(1, $models);
        self::assertSame('acme/vision-production', $models[0]->modelName);
        self::assertSame([
            'image_input',
            'document_input',
        ], array_map(static fn ($modality): string => $modality->value, $models[0]->modalities));
        self::assertContains('structured_output', $models[0]->supportedCapabilities);
        self::assertSame('0.250000', $models[0]->pricing?->inputPricePerMillion());
        self::assertSame('1.250000', $models[0]->pricing?->outputPricePerMillion());
        self::assertTrue($models[0]->pricing?->isComplete());

        $options = AiModelCatalog::optionsForProvider('openrouter', null, null, $models);
        self::assertArrayHasKey('acme/vision-production', $options);
        self::assertStringContainsString('Изображения и сканы', $options['acme/vision-production']);
        self::assertStringContainsString('$0.25', $options['acme/vision-production']);
        self::assertArrayNotHasKey('acme/preview-model', $options);
        self::assertArrayNotHasKey('acme/retired-model', $options);
        self::assertStringNotContainsString('openrouter-secret', serialize($models));

        $model = app(CreateModelConfiguration::class)->handle($owner, $provider, [
            'model_selection' => 'acme/vision-production',
            'display_name' => 'Acme Vision',
            'capabilities' => [AiCapability::GeneralAssistant->value],
        ]);
        $release = app(CreateAndActivateModelRelease::class)->handle($owner, $model, [
            'model_selection' => 'acme/vision-production',
            'is_enabled' => true,
        ]);

        self::assertSame('acme/vision-production', $release->model_name);
        self::assertSame(AiPricingSnapshot::SOURCE_CATALOG, $release->getPricingSnapshot()->pricingSource);
        self::assertSame('0.250000', $release->getPricingSnapshot()->inputPricePerMillion());
        self::assertContains('image_input', $release->capabilities);
        self::assertContains('document_input', $release->capabilities);

        Cache::flush();
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response(['error' => 'temporarily unavailable'], 503),
        ]);
        $legacyEditableRelease = app(CreateAndActivateModelRelease::class)->handle($owner, $model, [
            'model_selection' => 'acme/vision-production',
            'is_enabled' => true,
        ]);

        self::assertSame('acme/vision-production', $legacyEditableRelease->model_name);
        self::assertSame(
            $release->getPricingSnapshot()->toArray(),
            $legacyEditableRelease->getPricingSnapshot()->toArray(),
        );
        self::assertContains('document_input', $legacyEditableRelease->capabilities);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer openrouter-secret'));
    }

    public function test_discovery_uses_bounded_cache_and_returns_stale_models_after_provider_failure(): void
    {
        [$organization] = $this->organizationFixture('cache-clinic');
        $provider = $this->providerWithCredential($organization, 'openrouter');
        Cache::flush();
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::sequence()
                ->push(['data' => [$this->discoveredModel('cached/model')]], 200)
                ->push(['error' => 'temporarily unavailable'], 503),
        ]);

        $service = app(AiModelDiscoveryService::class);
        self::assertFalse($service->discover($provider->load('credential'))->stale);
        CarbonImmutable::setTestNow(now()->addSeconds(601));
        $stale = $service->discover($provider->fresh('credential'));

        self::assertTrue($stale->stale);
        self::assertTrue($stale->hasError());
        self::assertSame('cached/model', $stale->models()[0]->modelName);
        self::assertStringNotContainsString('openrouter-secret', (string) $stale->error);
        Http::assertSentCount(2);
        CarbonImmutable::setTestNow();
    }

    public function test_credentialless_openai_compatible_discovery_returns_active_models_without_inventing_price(): void
    {
        [$organization] = $this->organizationFixture('compatible-clinic');
        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_name' => 'openai_compatible',
            'display_name' => 'Local compatible endpoint',
            'options' => ['base_url' => 'https://compatible.example/v1'],
        ]);
        Http::fake([
            'https://compatible.example/v1/models' => Http::response([
                'data' => [
                    ['id' => 'tenant-model', 'owned_by' => 'tenant'],
                ],
            ], 200),
        ]);

        $result = app(AiModelDiscoveryService::class)->discover($provider);
        $definition = $result->models()[0] ?? null;

        self::assertNotNull($definition);
        self::assertSame('tenant-model', $definition->modelName);
        self::assertNull($definition->pricing);
        self::assertSame([], $definition->modalities);
        self::assertStringContainsString('compatible endpoint', $definition->summary ?? '');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://compatible.example/v1/models'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_discovery_keeps_a_model_when_provider_pricing_is_missing_or_malformed(): void
    {
        [$organization] = $this->organizationFixture('malformed-pricing-clinic');
        $provider = $this->providerWithCredential($organization, 'openrouter');
        Http::fake([
            'https://openrouter.ai/api/v1/models' => Http::response([
                'data' => [[
                    'id' => 'tenant/unknown-price',
                    'name' => 'Tenant model',
                    'architecture' => [
                        'input_modalities' => ['text'],
                        'output_modalities' => ['text'],
                    ],
                    'pricing' => [
                        'prompt' => 'not-a-price',
                        'completion' => '0.000001',
                        'request' => 'not-a-price',
                    ],
                ]],
            ], 200),
        ]);

        $definition = app(AiModelDiscoveryService::class)->discover($provider->load('credential'))->models()[0] ?? null;

        self::assertNotNull($definition);
        self::assertNull($definition->pricing);
    }

    public function test_discovery_rejects_unsafe_legacy_endpoint_options_without_making_a_request(): void
    {
        [$organization] = $this->organizationFixture('unsafe-endpoint-clinic');
        $provider = $this->providerWithCredential($organization, 'openai_compatible');
        $provider->update([
            'options' => [
                'base_url' => 'http://169.254.169.254/latest?secret=should-not-leak',
            ],
        ]);
        Http::fake();

        $result = app(AiModelDiscoveryService::class)->discover($provider->fresh('credential'));

        self::assertSame([], $result->models());
        self::assertStringNotContainsString('should-not-leak', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_discovery_rejects_cross_organization_credential_without_making_a_request(): void
    {
        [$organizationA] = $this->organizationFixture('tenant-a');
        $providerA = $this->providerWithCredential($organizationA, 'openrouter');
        [$organizationB] = $this->organizationFixture('tenant-b');
        $providerB = AiProviderConfiguration::create([
            'organization_id' => $organizationB->getKey(),
            'provider_name' => 'openrouter',
            'display_name' => 'Cross tenant provider',
        ]);
        $providerB->setRelation('credential', $providerA->load('credential')->credential);
        Http::fake();

        $result = app(AiModelDiscoveryService::class)->discover($providerB->load('credential'));

        self::assertSame([], $result->models());
        self::assertStringContainsString('действующий ключ', mb_strtolower((string) $result->error));
        Http::assertNothingSent();
    }

    public function test_knowledge_model_catalog_keeps_embeddings_and_reranking_out_of_chat_picker(): void
    {
        self::assertSame([
            'text-embedding-3-small',
            'text-embedding-3-large',
        ], array_keys(KnowledgeModelCatalog::optionsFor(KnowledgeModelCatalog::Embedding, 'openai')));
        self::assertSame([
            'rerank-v4.0-pro',
            'rerank-v4.0-fast',
        ], array_keys(KnowledgeModelCatalog::optionsFor(KnowledgeModelCatalog::Reranking, 'cohere')));
        self::assertSame([
            'voyage-4-large',
            'voyage-4',
            'voyage-4-lite',
        ], array_keys(KnowledgeModelCatalog::optionsFor(KnowledgeModelCatalog::Embedding, 'voyageai')));
        self::assertSame([
            'rerank-2.5',
            'rerank-2.5-lite',
        ], array_keys(KnowledgeModelCatalog::optionsFor(KnowledgeModelCatalog::Reranking, 'voyageai')));
        self::assertArrayNotHasKey('embed-v4.0', AiModelCatalog::optionsForProvider('cohere'));
        self::assertStringContainsString('1M токенов', KnowledgeModelCatalog::optionsFor(KnowledgeModelCatalog::Embedding, 'openai')['text-embedding-3-small']);
        self::assertStringContainsString('поисковые единицы', KnowledgeModelCatalog::optionsFor(KnowledgeModelCatalog::Reranking, 'cohere')['rerank-v4.0-fast']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{Organization, User} */
    private function organizationFixture(string $slug): array
    {
        $organization = Organization::factory()->create(['slug' => $slug]);
        $owner = User::factory()->forOrganization($organization, OrganizationRole::Owner)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $owner];
    }

    private function providerWithCredential(Organization $organization, string $providerName): AiProviderConfiguration
    {
        $credential = new OrganizationCredential([
            'provider' => $providerName,
            'credential_name' => $providerName.' key',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $organization->getKey();
        $credential->credentials = ['api_key' => 'openrouter-secret'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        return AiProviderConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_name' => $providerName,
            'display_name' => ucfirst($providerName),
            'credential_id' => $credential->getKey(),
        ]);
    }

    /** @return array<string, mixed> */
    private function discoveredModel(string $model): array
    {
        return [
            'id' => $model,
            'name' => 'Cached model',
            'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
            'pricing' => ['prompt' => '0.0000001', 'completion' => '0.0000002'],
        ];
    }
}
