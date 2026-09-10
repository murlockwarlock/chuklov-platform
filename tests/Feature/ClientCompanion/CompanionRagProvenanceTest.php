<?php

namespace Tests\Feature\ClientCompanion;

use App\Models\User;
use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\AiRunOrigin;
use App\Modules\AI\Domain\Enums\AiRunStatus;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\Models\AiRun;
use App\Modules\AI\Domain\Models\AiRunRagReference;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Engine\DynamicWorkflowAgent;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Channels\Domain\Contracts\MessagingChannel;
use App\Modules\Channels\Domain\ValueObjects\ChannelCapabilities;
use App\Modules\Channels\Domain\ValueObjects\CompanionOutboundChunk;
use App\Modules\Channels\Domain\ValueObjects\NotificationDeliveryResult;
use App\Modules\ClientCompanion\Application\Actions\AcceptCompanionMessage;
use App\Modules\ClientCompanion\Application\Services\CompanionMessageBodyReader;
use App\Modules\ClientCompanion\Application\Services\CompanionTurnProcessor;
use App\Modules\ClientCompanion\Domain\Enums\CompanionTurnStatus;
use App\Modules\Conversations\Domain\Models\ConversationMessage;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Knowledge\Application\CreateKnowledgeSource;
use App\Modules\Knowledge\Application\ProcessKnowledgeIngestion;
use App\Modules\Knowledge\Domain\Contracts\EmbeddingGenerator;
use App\Modules\Knowledge\Domain\Models\KnowledgeChunk;
use App\Modules\Knowledge\Domain\ValueObjects\EmbeddingConfiguration;
use App\Modules\MedicalProfiles\Domain\Contracts\MedicalEncryptorInterface;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Enums\OrganizationRole;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CompanionRagProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('queue.default', 'sync');
        $this->organization = Organization::create([
            'name' => 'R3 test organization',
            'slug' => 'r3-'.Str::uuid(),
            'timezone' => 'UTC',
        ]);
        $this->user = User::factory()->forOrganization($this->organization, OrganizationRole::Administrator)->create();
        $this->client = Client::factory()->forOrganization($this->organization)->create();
        app(OrganizationContext::class)->set($this->organization);
        config()->set('tenancy.default_organization_id', $this->organization->getKey());
        $this->bindDeterministicEmbeddings();
    }

    public function test_real_companion_path_persists_qualifying_rag_provenance_after_ingestion(): void
    {
        $this->createActivePrompt();
        $this->createConfiguredModel();
        $source = app(CreateKnowledgeSource::class)->handle($this->user, [
            'title' => 'R3 qualifying source',
            'type' => 'authored_text',
            'content' => 'KELP-47',
            'source_reference' => 'r3-regression',
            'client_companion_enabled' => true,
        ]);
        $revision = $source->revisions()->sole();
        if ($revision->fresh()->status->value !== 'ready') {
            app(ProcessKnowledgeIngestion::class)->handle(
                $this->organization->getKey(),
                $source->getKey(),
                $revision->getKey(),
            );
        }
        $revision->refresh();
        $chunk = KnowledgeChunk::query()->where('organization_id', $this->organization->getKey())->where('knowledge_revision_id', $revision->getKey())->sole();
        self::assertSame('ready', $revision->status->value);

        DynamicWorkflowAgent::fake([[
            'decision' => 'reply',
            'reply' => 'Контекст подтверждает маркер KELP-47.',
            'handoff_reason' => '',
            'suggested_safe_actions' => [],
        ]]);
        $channel = new RagProvenanceCompanionChannel;
        $this->app->instance(MessagingChannel::class, $channel);

        $turn = app(AcceptCompanionMessage::class)->handle(
            client: $this->client,
            channel: 'portal',
            body: 'KELP-47',
            idempotencyKey: 'r3-regression-'.Str::lower(Str::random(20)),
            originExternalId: null,
            locale: 'ru',
        );
        $turn->update(['burst_expires_at' => now()->subSecond()]);
        app(CompanionTurnProcessor::class)->handle($this->organization->getKey(), $turn->getKey());

        $turn->refresh();
        self::assertSame(CompanionTurnStatus::Completed, $turn->status);
        self::assertNotNull($turn->ai_run_id);
        $run = AiRun::query()->where('organization_id', $this->organization->getKey())->whereKey($turn->ai_run_id)->firstOrFail();
        self::assertSame(AiRunStatus::Succeeded, $run->status);
        self::assertSame(AiCapability::ClientCompanion, $run->capability);
        self::assertSame(AiRunOrigin::ClientCompanion, $run->origin);
        self::assertSame(1, data_get($run->context_provenance, 'rag_chunks_count'));

        $references = AiRunRagReference::query()
            ->where('organization_id', $this->organization->getKey())
            ->where('ai_run_id', $run->getKey())
            ->where('retrieval_type', 'initial')
            ->get();
        self::assertCount(1, $references);
        self::assertSame($source->getKey(), $references->sole()->knowledge_source_id);
        self::assertSame($revision->getKey(), $references->sole()->knowledge_revision_id);
        self::assertSame($chunk->getKey(), $references->sole()->knowledge_chunk_id);
        self::assertGreaterThanOrEqual(0.65, (float) $references->sole()->similarity_score);

        $payload = $run->payload()->firstOrFail();
        $answer = app(MedicalEncryptorInterface::class)->decryptField(
            $this->organization->getKey(),
            $payload->encrypted_output_text,
            $payload->encryption_key_version,
        );
        self::assertStringContainsString('KELP-47', (string) $answer);
        self::assertStringContainsString('KELP-47', app(CompanionMessageBodyReader::class)->read(
            $this->organization->getKey(),
            ConversationMessage::query()->findOrFail($turn->outbound_message_id),
        ));
    }

    private function createActivePrompt(): void
    {
        $prompt = AiPrompt::create([
            'organization_id' => $this->organization->getKey(),
            'key' => 'r3_companion',
            'name' => 'R3 Companion',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $this->organization->getKey(),
            'prompt_id' => $prompt->getKey(),
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Use only the supplied context and answer safely.',
            'user_prompt_template' => "Current message: {{current_message}}\nKnowledge: {{rag_context}}",
            'context_policy' => [
                'include_rag' => true,
                'rag_max_chunks' => 3,
                'rag_min_similarity' => 0.65,
                'allow_rag_degradation' => false,
                'allowed_context_types' => ['client_profile', 'health_context', 'rag'],
            ],
            'allowed_tools' => ['search_knowledge_base'],
            'activated_at' => Carbon::now(),
        ]);
        $prompt->update(['active_version_id' => $version->getKey()]);
    }

    private function createConfiguredModel(): void
    {
        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'R3 test credential',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $this->organization->getKey();
        $credential->credentials = ['api_key' => 'sk-test'];
        $credential->status = CredentialStatus::Active;
        $credential->save();
        $provider = AiProviderConfiguration::create([
            'organization_id' => $this->organization->getKey(),
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'is_enabled' => true,
            'health_status' => ProviderHealthStatus::Healthy,
            'credential_id' => $credential->getKey(),
            'tested_credential_revision' => $credential->revision_id,
            'tested_configuration_digest' => AiProviderExecutionConfiguration::digest('openai'),
        ]);
        $pricing = new AiPricingSnapshot(
            currency: 'USD',
            inputCostPerMillionMinorUnits: 15,
            outputCostPerMillionMinorUnits: 60,
        );
        $capabilities = [AiCapability::ClientCompanion->value];
        $model = AiModelConfiguration::create([
            'organization_id' => $this->organization->getKey(),
            'provider_config_id' => $provider->getKey(),
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'GPT 4o Mini',
            'is_enabled' => true,
            'capabilities' => $capabilities,
            'pricing_snapshot' => $pricing->toArray(),
            'failover_priority' => 1,
        ]);
        $release = AiModelRelease::create([
            'organization_id' => $this->organization->getKey(),
            'model_config_id' => $model->getKey(),
            'release_number' => 1,
            'status' => 'active',
            'provider_name' => 'openai',
            'model_name' => 'gpt-4o-mini',
            'capabilities' => $capabilities,
            'pricing_snapshot' => $pricing->toArray(),
            'activated_at' => Carbon::now(),
        ]);
        $model->update(['active_release_id' => $release->getKey()]);
    }

    private function bindDeterministicEmbeddings(): void
    {
        $this->app->bind(EmbeddingGenerator::class, static fn (): EmbeddingGenerator => new class implements EmbeddingGenerator
        {
            public function generate(int $organizationId, array $inputs, EmbeddingConfiguration $configuration): array
            {
                return array_map(static function (string $input) use ($configuration): array {
                    $vector = array_fill(0, $configuration->dimensions, 0.0);
                    $vector[0] = str_contains(strtolower($input), 'kelp-47') ? 1.0 : 0.0;
                    $vector[1] = 1.0;

                    return $vector;
                }, $inputs);
            }
        });
    }
}

final class RagProvenanceCompanionChannel implements MessagingChannel
{
    public function name(): string
    {
        return 'portal';
    }

    public function capabilities(): ChannelCapabilities
    {
        return new ChannelCapabilities(true, true, true, true);
    }

    public function sendCompanionChunk(CompanionOutboundChunk $chunk): NotificationDeliveryResult
    {
        return NotificationDeliveryResult::delivered('r3-test-message');
    }

    public function sendTyping(string $recipientExternalId): bool
    {
        return true;
    }
}
