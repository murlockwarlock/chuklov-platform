<?php

namespace Tests\Feature;

use App\Filament\Resources\SurveyDefinitions\Pages\ListSurveyDefinitions;
use App\Filament\Resources\SurveyDefinitions\SurveyDefinitionResource;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Scenarios\Application\MaterializeScenarioEvent;
use App\Modules\Scenarios\Domain\Enums\ScenarioEventStatus;
use App\Modules\Scenarios\Domain\Models\ScenarioEvent;
use App\Modules\Security\Domain\Models\AuditEvent;
use App\Modules\Surveys\Application\CompleteSurveyAttempt;
use App\Modules\Surveys\Application\CreateSurveyDefinition;
use App\Modules\Surveys\Application\CreateSurveyVersion;
use App\Modules\Surveys\Application\PublishSurveyVersion;
use App\Modules\Surveys\Application\SaveSurveyAttempt;
use App\Modules\Surveys\Application\StartSurveyAttempt;
use App\Modules\Surveys\Domain\Enums\SurveyAttemptStatus;
use App\Modules\Surveys\Domain\Enums\SurveyVersionStatus;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyComparison;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyReport;
use App\Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

final class MilestoneEightSurveyTest extends TestCase
{
    use RefreshDatabase;

    public function test_definition_validation_fails_closed_for_unknown_operators(): void
    {
        $data = $this->definitionData();
        $data['definition']['sections'][0]['questions'][1]['condition']['operator'] = 'execute_php';

        $this->expectException(ValidationException::class);
        app(SurveyDefinitionValidator::class)->validate($data['definition'], $data['scoring']);
    }

    public function test_published_version_is_immutable_and_new_version_does_not_rewrite_attempt(): void
    {
        [$organization, $actor, $client] = $this->fixture();
        $definition = $this->publishedDefinition($actor);
        $attempt = app(StartSurveyAttempt::class)->handle($client, $definition);
        $versionOne = $definition->activeVersion()->firstOrFail();

        try {
            $versionOne->definition = ['sections' => []];
            $versionOne->save();
            self::fail('Published version mutation was accepted.');
        } catch (LogicException) {
            self::assertTrue(true);
        }

        $versionTwoData = $this->definitionData();
        $versionTwoData['definition']['sections'][0]['questions'][0]['label'] = 'Updated label';
        $versionTwo = app(CreateSurveyVersion::class)->handle($actor, $definition, $versionTwoData);
        app(PublishSurveyVersion::class)->handle($actor, $versionTwo);

        self::assertSame($versionOne->getKey(), $attempt->survey_version_id);
        self::assertSame('Symptoms', $attempt->definition_snapshot['sections'][0]['questions'][0]['label']);
        self::assertSame(SurveyVersionStatus::Retired, $versionOne->fresh()->status);
        self::assertSame(2, $definition->fresh()->activeVersion()->value('version'));
        self::assertSame($organization->getKey(), $attempt->organization_id);
    }

    public function test_completion_is_condition_aware_deterministic_encrypted_and_retry_safe(): void
    {
        [, $actor, $client] = $this->fixture();
        $definition = $this->publishedDefinition($actor);
        $attempt = app(StartSurveyAttempt::class)->handle($client, $definition);

        $completed = app(CompleteSurveyAttempt::class)->handle($client, $attempt, [
            'symptoms' => 'high',
            'details' => 'Sensitive health detail',
            'energy' => 4,
        ]);
        $retried = app(CompleteSurveyAttempt::class)->handle($client, $completed, [
            'symptoms' => 'low',
        ]);

        self::assertSame(SurveyAttemptStatus::Completed, $retried->status);
        self::assertEquals(7.0, $retried->result_snapshot['metrics']['symptom_total']['value']);
        self::assertSame(['needs_attention'], $retried->result_snapshot['tags']);
        self::assertSame(1, SurveyReport::query()->where('survey_attempt_id', $attempt->getKey())->count());
        self::assertSame(1, ScenarioEvent::query()->where('event_name', 'survey.completed')->count());
        $audit = AuditEvent::query()->where('action', 'survey.attempt.completed')->sole();
        self::assertSame([
            'definition_key' => $definition->definition_key,
            'version' => 1,
            'tag_count' => 1,
            'metric_count' => 1,
        ], $audit->metadata);
        $event = ScenarioEvent::query()->where('event_name', 'survey.completed')->sole();
        app(MaterializeScenarioEvent::class)->handle($event->getKey());
        self::assertSame(ScenarioEventStatus::Processed, $event->fresh()->status);
        $stored = DB::table('survey_attempts')->where('id', $attempt->getKey())->value('answers_snapshot');
        self::assertIsString($stored);
        self::assertStringNotContainsString('Sensitive health detail', $stored);
        self::assertSame('high', $retried->answers_snapshot['symptoms']);
    }

    public function test_hidden_required_question_is_not_required(): void
    {
        [, $actor, $client] = $this->fixture();
        $attempt = app(StartSurveyAttempt::class)->handle($client, $this->publishedDefinition($actor));

        $completed = app(CompleteSurveyAttempt::class)->handle($client, $attempt, [
            'symptoms' => 'low',
            'energy' => 2,
        ]);

        self::assertSame(['symptoms', 'energy'], $completed->result_snapshot['visible_question_keys']);
    }

    public function test_draft_save_rejects_unbounded_sensitive_text(): void
    {
        [, $actor, $client] = $this->fixture();
        $attempt = app(StartSurveyAttempt::class)->handle($client, $this->publishedDefinition($actor));

        $this->expectException(ValidationException::class);
        app(SaveSurveyAttempt::class)->handle($client, $attempt, [
            'symptoms' => 'high',
            'details' => str_repeat('a', 10001),
        ]);
    }

    public function test_cross_organization_and_wrong_client_attempt_access_are_rejected(): void
    {
        [$organization, $actor, $client] = $this->fixture();
        $definition = $this->publishedDefinition($actor);
        $attempt = app(StartSurveyAttempt::class)->handle($client, $definition);
        $wrongClient = Client::factory()->forOrganization($organization)->create();

        $this->expectException(AuthorizationException::class);
        app(CompleteSurveyAttempt::class)->handle($wrongClient, $attempt, []);
    }

    public function test_compatible_repeat_emits_one_stagnation_event_and_incompatible_version_does_not_compare(): void
    {
        [, $actor, $client] = $this->fixture();
        $definition = $this->publishedDefinition($actor);
        $first = app(StartSurveyAttempt::class)->handle($client, $definition);
        app(CompleteSurveyAttempt::class)->handle($client, $first, ['symptoms' => 'high', 'details' => 'a', 'energy' => 2]);
        $second = app(StartSurveyAttempt::class)->handle($client, $definition);
        app(CompleteSurveyAttempt::class)->handle($client, $second, ['symptoms' => 'high', 'details' => 'b', 'energy' => 3]);
        app(CompleteSurveyAttempt::class)->handle($client, $second, []);

        $comparison = SurveyComparison::query()->where('current_attempt_id', $second->getKey())->sole();
        self::assertSame('stagnation_detected', $comparison->status);
        self::assertSame(1, ScenarioEvent::query()->where('event_name', 'TEST_STAGNATION_DETECTED')->count());

        $newData = $this->definitionData();
        $newData['metric_schema_key'] = 'symptom-schema-v2';
        $version = app(CreateSurveyVersion::class)->handle($actor, $definition, $newData);
        app(PublishSurveyVersion::class)->handle($actor, $version);
        $third = app(StartSurveyAttempt::class)->handle($client, $definition->refresh());
        app(CompleteSurveyAttempt::class)->handle($client, $third, ['symptoms' => 'high', 'details' => 'c', 'energy' => 4]);

        self::assertSame('not_comparable', SurveyComparison::query()->where('current_attempt_id', $third->getKey())->value('status'));
        self::assertSame(1, ScenarioEvent::query()->where('event_name', 'TEST_STAGNATION_DETECTED')->count());
    }

    public function test_portal_renders_start_completion_and_client_scoped_report_flow(): void
    {
        [$organization, $actor, $client] = $this->fixture();
        $definition = $this->publishedDefinition($actor);

        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.surveys.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Portal/Surveys')
                ->has('definitions', 1));
        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.surveys.start', $definition->getKey()))
            ->assertRedirect();
        $attempt = SurveyAttempt::query()->where('client_id', $client->getKey())->sole();
        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.surveys.show', $attempt->getKey()))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('Portal/SurveyTake')->where('attempt.id', $attempt->getKey()));
        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->post(route('portal.surveys.complete', $attempt->getKey()), ['answers' => ['symptoms' => 'low', 'energy' => 2]])
            ->assertRedirect();
        $report = SurveyReport::query()->where('client_id', $client->getKey())->sole();
        $this->withSession(['client_portal.client_id' => $client->getKey()])
            ->get(route('portal.surveys.report', $report->getKey()))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('Portal/SurveyReport')->where('report.title', 'Screening EN'));

        $otherClient = Client::factory()->forOrganization($organization)->create();
        $this->withSession(['client_portal.client_id' => $otherClient->getKey()])
            ->get(route('portal.surveys.report', $report->getKey()))
            ->assertNotFound();
    }

    public function test_crm_definition_list_is_organization_scoped(): void
    {
        [$organization, $actor] = $this->fixture();
        $own = $this->publishedDefinition($actor);
        $otherOrganization = Organization::factory()->create();
        $other = SurveyDefinition::query()->create([
            'organization_id' => $otherOrganization->getKey(),
            'definition_key' => 'other',
            'title' => 'Other survey',
            'is_available' => true,
        ]);
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($actor)
            ->get(SurveyDefinitionResource::getUrl('index'))
            ->assertOk();
        Livewire::test(ListSurveyDefinitions::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);
    }

    private function publishedDefinition(User $actor): SurveyDefinition
    {
        $definition = app(CreateSurveyDefinition::class)->handle($actor, $this->definitionData());
        app(PublishSurveyVersion::class)->handle($actor, $definition->versions()->sole());

        return $definition->refresh();
    }

    private function definitionData(): array
    {
        return [
            'definition_key' => 'screening-'.uniqid(),
            'title' => 'Screening',
            'title_en' => 'Screening EN',
            'description' => 'Deterministic screening',
            'description_en' => 'Deterministic screening EN',
            'metric_schema_key' => 'symptom-schema-v1',
            'source_reference' => 'test fixture',
            'definition' => [
                'sections' => [[
                    'key' => 'general',
                    'title' => 'General',
                    'questions' => [
                        ['key' => 'symptoms', 'type' => 'single_choice', 'label' => 'Symptoms', 'required' => true, 'options' => [
                            ['value' => 'low', 'label' => 'Low'],
                            ['value' => 'high', 'label' => 'High'],
                        ]],
                        ['key' => 'details', 'type' => 'long_text', 'label' => 'Details', 'required' => true, 'condition' => [
                            'question_key' => 'symptoms', 'operator' => 'equals', 'value' => 'high',
                        ]],
                        ['key' => 'energy', 'type' => 'integer', 'label' => 'Energy', 'required' => true],
                    ],
                ]],
            ],
            'scoring' => [
                'metrics' => [['key' => 'symptom_total', 'label' => 'Symptom total']],
                'rules' => [
                    ['question_key' => 'symptoms', 'metric_key' => 'symptom_total', 'operator' => 'value_map', 'points' => ['low' => 1, 'high' => 3]],
                    ['question_key' => 'energy', 'metric_key' => 'symptom_total', 'operator' => 'numeric_value'],
                ],
                'thresholds' => [['metric_key' => 'symptom_total', 'min' => 5, 'tag' => 'needs_attention', 'label' => 'Needs attention']],
                'comparison' => ['operator' => 'no_decrease', 'metric_keys' => ['symptom_total']],
            ],
        ];
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);

        return [$organization, $actor, $client];
    }
}
