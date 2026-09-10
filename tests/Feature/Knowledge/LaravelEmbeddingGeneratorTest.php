<?php

namespace Tests\Feature\Knowledge;

use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\Knowledge\Infrastructure\LaravelEmbeddingGenerator;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LaravelEmbeddingGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_embeddings_use_the_organization_provider_credential_instead_of_global_ai_config(): void
    {
        $organization = Organization::factory()->create();
        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'OpenAI embeddings',
            'revision_id' => '00000000-0000-4000-8000-000000000001',
            'status' => CredentialStatus::Active,
        ]);
        $credential->organization_id = $organization->getKey();
        $credential->credentials = ['api_key' => 'sk-organization-embedding-key'];
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'is_enabled' => true,
            'health_status' => ProviderHealthStatus::Healthy,
            'credential_id' => $credential->getKey(),
            'tested_credential_revision' => $credential->revision_id,
            'tested_configuration_digest' => AiProviderExecutionConfiguration::digest('openai'),
        ]);

        config()->set('rag.embedding.provider', 'openai');
        config()->set('rag.embedding.model', 'text-embedding-3-small');
        config()->set('rag.embedding.dimensions', 3);
        config()->set('ai.providers.openai.key', 'wrong-global-key');
        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
                'usage' => ['prompt_tokens' => 2],
            ], 200),
        ]);

        $embeddings = app(LaravelEmbeddingGenerator::class)->generate(
            (int) $organization->getKey(),
            ['sleep and recovery'],
            EmbeddingConfiguration::active(),
        );

        self::assertSame([[0.1, 0.2, 0.3]], $embeddings);
        Http::assertSent(static function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/embeddings'
                && $request->hasHeader('Authorization', 'Bearer sk-organization-embedding-key')
                && $request->data()['model'] === 'text-embedding-3-small'
                && $request->data()['dimensions'] === 3;
        });
        self::assertSame(ProviderHealthStatus::Healthy, $provider->fresh()->health_status);
    }
}
