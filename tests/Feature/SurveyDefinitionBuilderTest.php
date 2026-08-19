<?php

namespace Tests\Feature;

use App\Filament\Resources\SurveyDefinitions\Pages\CreateSurveyDefinition as CreateSurveyDefinitionPage;
use App\Filament\Resources\SurveyDefinitions\Pages\EditSurveyDefinition;
use App\Filament\Support\SurveyDefinitionFormMapper;
use App\Models\User;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Surveys\Application\CreateSurveyDefinition as CreateSurveyDefinitionAction;
use App\Modules\Surveys\Application\PublishSurveyVersion;
use App\Modules\Surveys\Application\UpdateSurveyDefinitionDraft;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

final class SurveyDefinitionBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_create_generates_technical_identities_without_raw_key_inputs(): void
    {
        [, $admin] = $this->fixture();
        $sectionKey = SurveyDefinitionFormMapper::newIdentity();
        $questionKey = SurveyDefinitionFormMapper::newIdentity();
        $goodValue = SurveyDefinitionFormMapper::newIdentity();
        $badValue = SurveyDefinitionFormMapper::newIdentity();
        $metricKey = SurveyDefinitionFormMapper::newIdentity();

        Livewire::actingAs($admin)
            ->test(CreateSurveyDefinitionPage::class)
            ->fillForm([
                'title' => 'Новый опросник',
                'description' => 'Описание',
                'sections' => [[
                    'key' => $sectionKey,
                    'title' => 'Раздел',
                    'questions' => [[
                        'key' => $questionKey,
                        'label' => 'Как вы себя чувствуете?',
                        'type' => 'single_choice',
                        'required' => true,
                        'options' => [
                            ['value' => $goodValue, 'label' => 'Хорошо'],
                            ['value' => $badValue, 'label' => 'Плохо'],
                        ],
                    ]],
                ]],
                'metrics' => [['key' => $metricKey, 'label' => 'Итог']],
                'rules' => [],
                'thresholds' => [],
                'comparison_metric_keys' => [],
                'is_available' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $definition = SurveyDefinition::query()->with('versions')->sole();
        $version = $definition->versions->sole();
        $question = $version->definition['sections'][0]['questions'][0];
        $options = $question['options'];
        $metric = $version->scoring['metrics'][0];

        self::assertNotEmpty($definition->definition_key);
        self::assertNotEmpty($version->metric_schema_key);
        self::assertNull($version->source_reference);
        self::assertNotSame('Новый опросник', $definition->definition_key);
        self::assertNotSame('Раздел', $version->definition['sections'][0]['key']);
        self::assertNotSame('Как вы себя чувствуете?', $question['key']);
        self::assertNotSame('Хорошо', $options[0]['value']);
        self::assertNotSame('Итог', $metric['key']);
        self::assertCount(2, array_unique(array_column($options, 'value')));
    }

    public function test_edit_preserves_identities_source_reference_and_compatibility_after_text_and_order_changes(): void
    {
        [$organization, $admin] = $this->fixture();
        $definition = app(CreateSurveyDefinitionAction::class)->handle($admin, $this->canonicalData());
        $version = $definition->versions()->sole();
        app(PublishSurveyVersion::class)->handle($admin, $version);

        $state = SurveyDefinitionFormMapper::denormalize($version);
        $state['title'] = 'Изменённое название';
        $state['sections'][0]['questions'][0]['label'] = 'Новый текст вопроса';
        $state['sections'][0]['questions'][0]['options'][0]['label'] = 'Отлично';
        $state['metrics'][0]['label'] = 'Итог изменён';
        $state['thresholds'][0]['label'] = 'Нужно внимание изменено';
        [$source, $dependent, $number] = $state['sections'][0]['questions'];
        $state['sections'][0]['questions'] = [$source, $number, $dependent];

        $component = Livewire::actingAs($admin)
            ->test(EditSurveyDefinition::class, ['record' => $definition->getKey()])
            ->fillForm($state);
        $component->call('save')->assertHasNoFormErrors();

        $draft = $definition->refresh()->versions()->where('status', 'draft')->latest('version')->firstOrFail();
        $questions = $draft->definition['sections'][0]['questions'];

        self::assertSame($organization->getKey(), $draft->organization_id);
        self::assertSame($definition->definition_key, $definition->fresh()->definition_key);
        self::assertSame('section-main', $draft->definition['sections'][0]['key']);
        self::assertSame(['q-source', 'q-number', 'q-dependent'], array_column($questions, 'key'));
        self::assertSame('q-source', $questions[2]['condition']['question_key']);
        self::assertSame('option-poor', $questions[0]['options'][1]['value']);
        self::assertSame('metric-total', $draft->scoring['metrics'][0]['key']);
        self::assertSame('threshold-attention', $draft->scoring['thresholds'][0]['tag']);
        self::assertSame('Отлично', $questions[0]['options'][0]['label']);
        self::assertSame('Итог изменён', $draft->scoring['metrics'][0]['label']);
        self::assertSame('Нужно внимание изменено', $draft->scoring['thresholds'][0]['label']);
        self::assertSame('scale-v1', $draft->metric_schema_key);
        self::assertSame('imported/source.json', $draft->source_reference);
        self::assertSame('Изменённое название', $draft->title);

        $reopened = SurveyDefinitionFormMapper::denormalize($draft);
        Livewire::actingAs($admin)
            ->test(EditSurveyDefinition::class, ['record' => $definition->getKey()])
            ->fillForm($reopened)
            ->call('save')
            ->assertHasNoFormErrors();

        $draft->refresh();
        self::assertSame(['q-source', 'q-number', 'q-dependent'], array_column($draft->definition['sections'][0]['questions'], 'key'));
        self::assertSame('q-source', $draft->definition['sections'][0]['questions'][2]['condition']['question_key']);
        self::assertSame('metric-total', $draft->scoring['rules'][0]['metric_key']);
        self::assertSame(['option-good', 'option-poor'], array_keys($draft->scoring['rules'][0]['points']));
    }

    public function test_imported_null_schema_stays_null_until_comparison_is_enabled(): void
    {
        [, $admin] = $this->fixture();
        $data = $this->canonicalData();
        unset($data['metric_schema_key']);
        $data['scoring']['comparison'] = null;
        $definition = app(CreateSurveyDefinitionAction::class)->handle($admin, $data);
        $draft = $definition->versions()->sole();

        self::assertNull($draft->metric_schema_key);

        $state = SurveyDefinitionFormMapper::denormalize($draft);
        $state['comparison_metric_keys'] = ['metric-total'];
        app(UpdateSurveyDefinitionDraft::class)->handle($admin, $definition, SurveyDefinitionFormMapper::normalize($state));

        self::assertNotNull($definition->refresh()->versions()->sole()->metric_schema_key);
    }

    public function test_reordering_a_dependent_question_before_its_source_blocks_save(): void
    {
        [, $admin] = $this->fixture();
        $definition = app(CreateSurveyDefinitionAction::class)->handle($admin, $this->canonicalData());
        $version = $definition->versions()->sole();
        app(PublishSurveyVersion::class)->handle($admin, $version);
        $state = SurveyDefinitionFormMapper::denormalize($version);
        [$source, $dependent, $number] = $state['sections'][0]['questions'];
        $state['sections'][0]['questions'] = [$dependent, $source, $number];

        Livewire::actingAs($admin)
            ->test(EditSurveyDefinition::class, ['record' => $definition->getKey()])
            ->fillForm($state)
            ->call('save')
            ->assertHasErrors();

        self::assertSame(1, $definition->refresh()->versions()->count());
    }

    public function test_comparison_disable_reenable_and_new_scale_follow_the_compatibility_lifecycle(): void
    {
        [, $admin] = $this->fixture();
        $definition = app(CreateSurveyDefinitionAction::class)->handle($admin, $this->canonicalData());
        $firstDraft = $definition->versions()->sole();
        $originalKey = $firstDraft->metric_schema_key;

        $disabled = SurveyDefinitionFormMapper::denormalize($firstDraft);
        $disabled['comparison_metric_keys'] = [];
        app(UpdateSurveyDefinitionDraft::class)->handle($admin, $definition, SurveyDefinitionFormMapper::normalize($disabled));
        $draft = $definition->refresh()->versions()->latest('version')->firstOrFail();
        self::assertNull($draft->scoring['comparison']);
        self::assertSame($originalKey, $draft->metric_schema_key);

        $reenabled = SurveyDefinitionFormMapper::denormalize($draft);
        $reenabled['comparison_metric_keys'] = ['metric-total'];
        app(UpdateSurveyDefinitionDraft::class)->handle($admin, $definition, SurveyDefinitionFormMapper::normalize($reenabled));
        $draft = $definition->refresh()->versions()->latest('version')->firstOrFail();
        self::assertSame($originalKey, $draft->metric_schema_key);

        $newScale = SurveyDefinitionFormMapper::denormalize($draft);
        $newScale['comparison_metric_keys'] = ['metric-total'];
        $newScale['start_new_metric_scale'] = true;
        app(UpdateSurveyDefinitionDraft::class)->handle($admin, $definition, SurveyDefinitionFormMapper::normalize($newScale));
        $latest = $definition->refresh()->versions()->latest('version')->firstOrFail();
        self::assertNotSame($originalKey, $latest->metric_schema_key);
    }

    public function test_imported_definition_key_is_immutable(): void
    {
        [, $admin] = $this->fixture();
        $definition = app(CreateSurveyDefinitionAction::class)->handle($admin, $this->canonicalData());

        $this->expectException(LogicException::class);
        $definition->definition_key = 'changed';
        $definition->save();
    }

    /** @return array<string, mixed> */
    private function canonicalData(): array
    {
        return [
            'definition_key' => 'imported-definition-'.Str::random(8),
            'title' => 'Исходное название',
            'title_en' => 'Original title',
            'description' => 'Описание',
            'description_en' => 'Description',
            'metric_schema_key' => 'scale-v1',
            'source_reference' => 'imported/source.json',
            'definition' => [
                'sections' => [[
                    'key' => 'section-main',
                    'title' => 'Раздел',
                    'questions' => [
                        [
                            'key' => 'q-source',
                            'type' => 'single_choice',
                            'label' => 'Вопрос',
                            'required' => true,
                            'options' => [
                                ['value' => 'option-good', 'label' => 'Хорошо'],
                                ['value' => 'option-poor', 'label' => 'Плохо'],
                            ],
                        ],
                        [
                            'key' => 'q-dependent',
                            'type' => 'long_text',
                            'label' => 'Комментарий',
                            'required' => false,
                            'condition' => ['question_key' => 'q-source', 'operator' => 'equals', 'value' => 'option-poor'],
                        ],
                        ['key' => 'q-number', 'type' => 'integer', 'label' => 'Оценка', 'required' => true],
                    ],
                ]],
            ],
            'scoring' => [
                'metrics' => [['key' => 'metric-total', 'label' => 'Итог']],
                'rules' => [
                    ['question_key' => 'q-source', 'metric_key' => 'metric-total', 'operator' => 'value_map', 'points' => ['option-good' => 1, 'option-poor' => 3]],
                    ['question_key' => 'q-number', 'metric_key' => 'metric-total', 'operator' => 'numeric_value'],
                ],
                'thresholds' => [['metric_key' => 'metric-total', 'min' => 3, 'tag' => 'threshold-attention', 'label' => 'Нужно внимание']],
                'comparison' => ['operator' => 'no_decrease', 'metric_keys' => ['metric-total']],
            ],
            'is_available' => true,
        ];
    }

    /** @return array{0: Organization, 1: User} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $admin = User::factory()->forOrganization($organization)->create();
        config()->set('tenancy.default_organization_id', $organization->getKey());
        app(OrganizationContext::class)->set($organization);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        return [$organization, $admin];
    }
}
