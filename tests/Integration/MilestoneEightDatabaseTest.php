<?php

namespace Tests\Integration;

use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Surveys\Application\CreateSurveyDefinition;
use App\Modules\Surveys\Application\PublishSurveyVersion;
use App\Modules\Surveys\Application\StartSurveyAttempt;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MilestoneEightDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgresql_composite_foreign_key_rejects_cross_organization_attempt_version(): void
    {
        [, $actorA] = $this->fixture();
        $definition = app(CreateSurveyDefinition::class)->handle($actorA, $this->definitionData());
        app(PublishSurveyVersion::class)->handle($actorA, $definition->versions()->sole());
        $version = $definition->refresh()->activeVersion()->firstOrFail();

        $organizationB = $this->organization();
        $clientB = Client::factory()->forOrganization($organizationB)->create();

        $this->expectException(QueryException::class);
        DB::table('survey_attempts')->insert([
            'organization_id' => $organizationB->getKey(),
            'client_id' => $clientB->getKey(),
            'survey_definition_id' => $definition->getKey(),
            'survey_version_id' => $version->getKey(),
            'status' => 'in_progress',
            'definition_snapshot' => 'encrypted',
            'answers_snapshot' => null,
            'scoring_snapshot' => 'encrypted',
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_postgresql_composite_foreign_key_rejects_wrong_client_report(): void
    {
        [$organization, $actor, $client] = $this->fixture();
        $definition = app(CreateSurveyDefinition::class)->handle($actor, $this->definitionData());
        app(PublishSurveyVersion::class)->handle($actor, $definition->versions()->sole());
        $attempt = app(StartSurveyAttempt::class)->handle($client, $definition->refresh());
        $wrongClient = Client::factory()->forOrganization($organization)->create();

        $this->expectException(QueryException::class);
        DB::table('survey_reports')->insert([
            'organization_id' => $organization->getKey(),
            'client_id' => $wrongClient->getKey(),
            'survey_attempt_id' => $attempt->getKey(),
            'survey_version_id' => $attempt->survey_version_id,
            'title' => 'Test',
            'report_snapshot' => 'encrypted',
            'materialized_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{Organization, User, Client} */
    private function fixture(): array
    {
        $organization = $this->organization();
        $actor = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $actor, $client];
    }

    private function organization(): Organization
    {
        $organization = new Organization;
        $organization->forceFill([
            'name' => 'Test organization',
            'slug' => 'test-'.Str::uuid(),
            'timezone' => 'UTC',
        ])->save();

        return $organization;
    }

    /** @return array<string, mixed> */
    private function definitionData(): array
    {
        return [
            'definition_key' => 'database-test',
            'title' => 'Database test',
            'definition' => ['sections' => [[
                'key' => 'general',
                'title' => 'General',
                'questions' => [[
                    'key' => 'answer',
                    'type' => 'boolean',
                    'label' => 'Answer',
                    'required' => true,
                ]],
            ]]],
            'scoring' => [
                'metrics' => [['key' => 'total', 'label' => 'Total']],
                'rules' => [],
                'thresholds' => [],
            ],
        ];
    }
}
