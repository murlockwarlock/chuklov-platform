<?php

namespace Tests\Feature;

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\SurveyAttempts\Pages\ListSurveyAttempts;
use App\Filament\Resources\SurveyAttempts\Pages\ViewSurveyAttempt;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Organizations\Domain\Models\OrganizationFeatureFlag;
use App\Modules\Surveys\Application\CreateSurveyDefinition;
use App\Modules\Surveys\Application\PublishSurveyVersion;
use App\Modules\Surveys\Application\StartSurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CrmEntityClickThroughTest extends TestCase
{
    use RefreshDatabase;

    public function test_survey_attempt_list_links_to_the_canonical_client_view(): void
    {
        [$actor, $client, $attempt] = $this->surveyAttemptFixture();

        $html = Livewire::actingAs($actor)
            ->test(ListSurveyAttempts::class)
            ->html();

        self::assertStringContainsString(
            'href="'.ClientResource::getUrl('view', ['record' => $client->getKey()]).'"',
            $html,
        );
        self::assertStringContainsString((string) $attempt->getKey(), $html);
    }

    public function test_survey_attempt_view_links_to_the_canonical_client_view(): void
    {
        [$actor, $client, $attempt] = $this->surveyAttemptFixture();

        $html = Livewire::actingAs($actor)
            ->test(ViewSurveyAttempt::class, ['record' => $attempt->getKey()])
            ->html();

        self::assertStringContainsString(
            'href="'.ClientResource::getUrl('view', ['record' => $client->getKey()]).'"',
            $html,
        );
    }

    /** @return array{0: User, 1: Client, 2: SurveyAttempt} */
    private function surveyAttemptFixture(): array
    {
        $organization = Organization::factory()->create();
        $actor = User::factory()->forOrganization($organization)->create();
        $client = Client::factory()->forOrganization($organization)->create(['full_name' => 'Murlock Warlock']);
        OrganizationFeatureFlag::factory()->forOrganization($organization)->create([
            'feature_key' => 'client_records',
            'enabled' => true,
        ]);
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $definition = app(CreateSurveyDefinition::class)->handle($actor, [
            'definition_key' => 'click-through-'.uniqid(),
            'title' => 'Click-through survey',
            'title_en' => null,
            'description' => null,
            'description_en' => null,
            'metric_schema_key' => null,
            'source_reference' => null,
            'definition' => [
                'sections' => [[
                    'key' => 'general',
                    'title' => 'General',
                    'questions' => [[
                        'key' => 'score',
                        'type' => 'integer',
                        'label' => 'Answer',
                        'required' => false,
                    ]],
                ]],
            ],
            'scoring' => [
                'metrics' => [['key' => 'score', 'label' => 'Score']],
                'rules' => [['question_key' => 'score', 'metric_key' => 'score', 'operator' => 'numeric_value']],
                'thresholds' => [],
            ],
        ]);
        app(PublishSurveyVersion::class)->handle($actor, $definition->versions()->sole());
        $attempt = app(StartSurveyAttempt::class)->handle($client, $definition);

        return [$actor, $client, $attempt];
    }
}
