<?php

namespace Tests\Feature\AI;

use App\Modules\AI\Domain\Enums\AiCapability;
use App\Modules\AI\Domain\Enums\ProviderHealthStatus;
use App\Modules\AI\Domain\Models\AiEvalSuite;
use App\Modules\AI\Domain\Models\AiModelConfiguration;
use App\Modules\AI\Domain\Models\AiModelRelease;
use App\Modules\AI\Domain\Models\AiPrompt;
use App\Modules\AI\Domain\Models\AiPromptVersion;
use App\Modules\AI\Domain\Models\AiProviderConfiguration;
use App\Modules\AI\Domain\ValueObjects\AiPricingSnapshot;
use App\Modules\AI\Infrastructure\Providers\AiProviderExecutionConfiguration;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Security\Domain\Enums\CredentialStatus;
use App\Modules\Security\Domain\Models\OrganizationCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiEvaluationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_observability_migration_backfills_legacy_pass_percentage_without_rewriting_results(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            self::markTestSkipped('This local migration compatibility probe uses SQLite; PostgreSQL migration coverage runs in integration CI.');
        }

        $organization = Organization::factory()->create();
        $credential = new OrganizationCredential([
            'provider' => 'openai',
            'credential_name' => 'Migration test',
            'revision_id' => (string) Str::uuid(),
        ]);
        $credential->organization_id = $organization->getKey();
        $credential->credentials = ['api_key' => 'sk-test'];
        $credential->status = CredentialStatus::Active;
        $credential->save();

        $provider = AiProviderConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_name' => 'openai',
            'display_name' => 'OpenAI',
            'health_status' => ProviderHealthStatus::Healthy,
            'credential_id' => $credential->getKey(),
            'tested_credential_revision' => $credential->revision_id,
            'tested_configuration_digest' => AiProviderExecutionConfiguration::digest('openai'),
        ]);
        $pricing = new AiPricingSnapshot(currency: 'USD');
        $model = AiModelConfiguration::create([
            'organization_id' => $organization->getKey(),
            'provider_config_id' => $provider->getKey(),
            'model_name' => 'gpt-4o-mini',
            'display_name' => 'GPT-4o mini',
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => $pricing->toArray(),
        ]);
        $release = AiModelRelease::create([
            'organization_id' => $organization->getKey(),
            'model_config_id' => $model->getKey(),
            'release_number' => 1,
            'provider_name' => 'openai',
            'model_name' => 'gpt-4o-mini',
            'capabilities' => [AiCapability::ClientCompanion->value],
            'pricing_snapshot' => $pricing->toArray(),
        ]);
        $model->update(['active_release_id' => $release->getKey()]);
        $prompt = AiPrompt::create([
            'organization_id' => $organization->getKey(),
            'key' => 'migration_prompt',
            'name' => 'Migration prompt',
            'capability' => AiCapability::ClientCompanion,
        ]);
        $version = AiPromptVersion::create([
            'organization_id' => $organization->getKey(),
            'prompt_id' => $prompt->getKey(),
            'version' => 1,
            'status' => 'active',
            'system_prompt' => 'Synthetic migration test.',
            'user_prompt_template' => '{{query}}',
        ]);
        $prompt->update(['active_version_id' => $version->getKey()]);
        $suite = AiEvalSuite::create([
            'organization_id' => $organization->getKey(),
            'key' => 'migration_suite',
            'name' => 'Migration suite',
            'capability' => AiCapability::ClientCompanion,
            'prompt_id' => $prompt->getKey(),
        ]);

        $legacyResults = ['cases' => [['passed' => true], ['passed' => true], ['passed' => true], ['passed' => false]]];
        $legacyId = DB::table('ai_eval_runs')->insertGetId([
            'organization_id' => $organization->getKey(),
            'eval_suite_id' => $suite->getKey(),
            'prompt_version_id' => $version->getKey(),
            'model_release_id' => $release->getKey(),
            'total_cases' => 4,
            'passed_cases' => 3,
            'failed_cases' => 1,
            'results_payload' => json_encode($legacyResults, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require base_path('database/migrations/2026_08_23_100000_expand_ai_evaluation_observability.php');
        $migration->down();
        $migration->up();

        $legacyRun = DB::table('ai_eval_runs')->where('id', $legacyId)->first();
        self::assertNotNull($legacyRun);
        self::assertSame(75.0, (float) $legacyRun->pass_percentage);
        self::assertSame($legacyResults, json_decode($legacyRun->results_payload, true, 64, JSON_THROW_ON_ERROR));
        self::assertNull($legacyRun->metrics_payload);
        self::assertNull($legacyRun->provenance_snapshot);
    }
}
