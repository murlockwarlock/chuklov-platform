<?php

namespace Tests\Feature;

use App\Filament\Resources\SurveyDefinitions\Pages\CreateSurveyDefinition as CreateSurveyDefinitionPage;
use App\Filament\Resources\SurveyDefinitions\Pages\EditSurveyDefinition;
use App\Filament\Support\SurveyDefinitionFormMapper;
use App\Models\User;
use App\Modules\Identity\Domain\Models\Client;
use App\Modules\Organizations\Application\OrganizationContext;
use App\Modules\Organizations\Domain\Models\Organization;
use App\Modules\Surveys\Application\CreateSurveyDefinition as CreateSurveyDefinitionAction;
use App\Modules\Surveys\Application\InstallPlatformSurveyCatalog;
use App\Modules\Surveys\Application\PlatformSurveyCatalog;
use App\Modules\Surveys\Application\PublishSurveyVersion;
use App\Modules\Surveys\Application\StartSurveyAttempt;
use App\Modules\Surveys\Application\UpdateSurveyDefinitionDraft;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Tabs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

final class SurveyDefinitionBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_uses_human_tabs_and_collapsible_repeater_summaries(): void
    {
        [, $admin] = $this->fixture();
        $component = Livewire::actingAs($admin)->test(CreateSurveyDefinitionPage::class);
        $tabs = $component->instance()->getSchema('form')->getComponents()[0];

        self::assertInstanceOf(Tabs::class, $tabs);
        self::assertSame([
            'Основное',
            'Вопросы',
            'Подсчёт результата',
            'Результат для клиента',
            'Публикация / версия',
        ], array_map(
            static fn ($tab): string => (string) $tab->getLabel(),
            $tabs->getChildSchema()->getComponents(),
        ));

        $questionsTab = $tabs->getChildSchema()->getComponents()[1];
        $questionsSection = $questionsTab->getChildSchema()->getComponents()[0];
        $sections = $questionsSection->getChildComponents()[0];

        self::assertInstanceOf(Repeater::class, $sections);
        self::assertTrue($sections->isCollapsible());
        self::assertTrue($sections->hasItemLabels());
    }

    public function test_edit_header_keeps_save_and_publish_actions_visible(): void
    {
        [, $admin] = $this->fixture();
        $definition = app(CreateSurveyDefinitionAction::class)->handle($admin, $this->canonicalData());

        Livewire::actingAs($admin)
            ->test(EditSurveyDefinition::class, ['record' => $definition->getKey()])
            ->assertSee('Сохранить черновик')
            ->assertSee('Опубликовать черновик');
    }

    public function test_repeater_add_lifecycle_generates_technical_identities_before_persistence(): void
    {
        [, $admin] = $this->fixture();
        $component = Livewire::actingAs($admin)->test(CreateSurveyDefinitionPage::class);
        $component->set('surveyBuilderTab', '1');
        $component->callFormComponentAction('1.sections', 'add');
        $state = $component->get('data');
        $initialSectionItemKey = array_key_first($state['sections']);
        $sectionItemKey = array_key_last($state['sections']);
        $component->callFormComponentAction('1.sections', 'delete', [], ['item' => $initialSectionItemKey]);
        $state = $component->get('data');
        $sectionKey = $state['sections'][$sectionItemKey]['key'];

        $component->callFormComponentAction("1.sections.{$sectionItemKey}.questions", 'add');
        $state = $component->get('data');
        $initialQuestionItemKey = array_key_first($state['sections'][$sectionItemKey]['questions']);
        $questionItemKey = array_key_last($state['sections'][$sectionItemKey]['questions']);
        $component->callFormComponentAction("1.sections.{$sectionItemKey}.questions", 'delete', [], ['item' => $initialQuestionItemKey]);
        $state = $component->get('data');
        $questionKey = $state['sections'][$sectionItemKey]['questions'][$questionItemKey]['key'];
        $state['sections'][$sectionItemKey]['questions'][$questionItemKey]['type'] = 'single_choice';
        $component->fillForm($state);

        $component->callFormComponentAction("1.sections.{$sectionItemKey}.questions.{$questionItemKey}.options", 'add');
        $component->set('surveyBuilderTab', '2');
        $component->callFormComponentAction('2.metrics', 'add');
        $component->callFormComponentAction('3.thresholds', 'add');

        $state = $component->get('data');
        $initialMetricItemKey = array_key_first($state['metrics']);
        $metricItemKey = array_key_last($state['metrics']);
        $component->callFormComponentAction('2.metrics', 'delete', [], ['item' => $initialMetricItemKey]);
        $initialThresholdItemKey = array_key_first($state['thresholds']);
        $thresholdItemKey = array_key_last($state['thresholds']);
        $component->callFormComponentAction('3.thresholds', 'delete', [], ['item' => $initialThresholdItemKey]);
        $state = $component->get('data');
        $optionItemKeys = array_keys($state['sections'][$sectionItemKey]['questions'][$questionItemKey]['options']);
        $optionValues = array_map(
            fn (string|int $itemKey): mixed => $state['sections'][$sectionItemKey]['questions'][$questionItemKey]['options'][$itemKey]['value'],
            $optionItemKeys,
        );
        $metricKey = $state['metrics'][$metricItemKey]['key'];
        $thresholdTag = $state['thresholds'][$thresholdItemKey]['tag'];

        foreach ([$sectionKey, $questionKey, ...$optionValues, $metricKey, $thresholdTag] as $identity) {
            self::assertIsString($identity);
            self::assertNotSame('', $identity);
        }
        self::assertGreaterThanOrEqual(2, count($optionValues));
        self::assertCount(count($optionValues), array_unique($optionValues));

        $state['title'] = 'Новый опросник';
        $state['description'] = 'Описание';
        $state['sections'][$sectionItemKey]['title'] = 'Раздел';
        $state['sections'][$sectionItemKey]['questions'][$questionItemKey]['label'] = 'Как вы себя чувствуете?';
        $state['sections'][$sectionItemKey]['questions'][$questionItemKey]['required'] = true;
        $optionLabels = ['Хорошо', 'Плохо', 'Ещё один вариант'];
        foreach ($optionItemKeys as $index => $optionItemKey) {
            $state['sections'][$sectionItemKey]['questions'][$questionItemKey]['options'][$optionItemKey]['label'] = $optionLabels[$index] ?? 'Вариант ответа';
        }
        $state['metrics'][$metricItemKey]['label'] = 'Итог';
        $state['thresholds'][$thresholdItemKey]['metric_key'] = $metricKey;
        $state['thresholds'][$thresholdItemKey]['min'] = 1;
        $state['thresholds'][$thresholdItemKey]['label'] = 'Результат';
        $state['rules'] = [[
            'question_key' => $questionKey,
            'metric_key' => $metricKey,
            'operator' => 'value_map',
            'points' => array_map(
                static fn (string $value, int $index): array => ['value' => $value, 'points' => $index],
                $optionValues,
                array_keys($optionValues),
            ),
        ]];
        $state['comparison_metric_keys'] = [];
        $state['is_available'] = true;

        $component->fillForm($state)
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $definition = SurveyDefinition::query()->with('versions')->sole();
        $version = $definition->versions->sole();
        $question = $version->definition['sections'][0]['questions'][0];
        $options = $question['options'];
        $metric = $version->scoring['metrics'][0];
        $threshold = $version->scoring['thresholds'][0];

        self::assertNotEmpty($definition->definition_key);
        self::assertNotEmpty($version->metric_schema_key);
        self::assertNull($version->source_reference);
        self::assertSame($sectionKey, $version->definition['sections'][0]['key']);
        self::assertSame($questionKey, $question['key']);
        self::assertSame($optionValues, array_column($options, 'value'));
        self::assertSame($metricKey, $metric['key']);
        self::assertSame($thresholdTag, $threshold['tag']);
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

    public function test_fractional_integer_condition_fails_without_persistence_and_whole_integer_is_preserved(): void
    {
        [, $admin] = $this->fixture();
        $definition = app(CreateSurveyDefinitionAction::class)->handle($admin, $this->canonicalData());

        foreach ([
            ['equals', 1.9],
            ['greater_than', 1.9],
            ['less_than', -2.5],
        ] as [$operator, $value]) {
            $state = $this->integerConditionState($definition->refresh()->versions()->sole(), $operator, $value);

            Livewire::actingAs($admin)
                ->test(EditSurveyDefinition::class, ['record' => $definition->getKey()])
                ->fillForm($state)
                ->call('save')
                ->assertHasErrors();

            self::assertSame(
                ['question_key' => 'q-source', 'operator' => 'equals', 'value' => 'option-poor'],
                $definition->refresh()->versions()->sole()->definition['sections'][0]['questions'][1]['condition'],
            );
        }

        foreach ([
            ['equals', 1, 1],
            ['greater_than', '2', 2],
            ['less_than', -3, -3],
        ] as [$operator, $value, $expected]) {
            $state = $this->integerConditionState($definition->refresh()->versions()->sole(), $operator, $value);

            Livewire::actingAs($admin)
                ->test(EditSurveyDefinition::class, ['record' => $definition->getKey()])
                ->fillForm($state)
                ->call('save')
                ->assertHasNoFormErrors();

            self::assertSame(
                $expected,
                $definition->refresh()->versions()->sole()->definition['sections'][0]['questions'][2]['condition']['value'],
            );
        }
    }

    public function test_legacy_number_equality_survives_unrelated_edit_and_publishes_but_ordering_stays_authoritative(): void
    {
        [, $admin] = $this->fixture();
        $data = $this->canonicalData();
        $data['definition']['sections'][0]['questions'][2]['type'] = 'number';
        [$source, $dependent, $number] = $data['definition']['sections'][0]['questions'];
        $dependent['condition'] = ['question_key' => 'q-number', 'operator' => 'equals', 'value' => 5];
        $data['definition']['sections'][0]['questions'] = [$source, $number, $dependent];
        $definition = app(CreateSurveyDefinitionAction::class)->handle($admin, $data);
        $version = $definition->versions()->sole();
        $state = SurveyDefinitionFormMapper::denormalize($version);

        self::assertSame(
            ['question_key' => 'q-number', 'operator' => 'equals', 'value' => 5],
            $state['sections'][0]['questions'][2]['condition_legacy'],
        );
        $state['title'] = 'Изменено без изменения условия';

        Livewire::actingAs($admin)
            ->test(EditSurveyDefinition::class, ['record' => $definition->getKey()])
            ->fillForm($state)
            ->call('save')
            ->assertHasNoFormErrors();

        $draft = $definition->refresh()->versions()->sole();
        self::assertSame(
            ['question_key' => 'q-number', 'operator' => 'equals', 'value' => 5],
            $draft->definition['sections'][0]['questions'][2]['condition'],
        );
        app(PublishSurveyVersion::class)->handle($admin, $draft);
        self::assertSame('published', $draft->fresh()->status->value);

        $invalidState = SurveyDefinitionFormMapper::denormalize($draft->fresh());
        [$source, $number, $dependent] = $invalidState['sections'][0]['questions'];
        $invalidState['sections'][0]['questions'] = [$source, $dependent, $number];

        Livewire::actingAs($admin)
            ->test(EditSurveyDefinition::class, ['record' => $definition->getKey()])
            ->fillForm($invalidState)
            ->call('save')
            ->assertHasErrors();

        self::assertSame('published', $draft->fresh()->status->value);
    }

    public function test_platform_scoring_edits_create_a_new_version_without_rewriting_historical_attempts(): void
    {
        [$organization, $admin] = $this->fixture();
        app(InstallPlatformSurveyCatalog::class)->handle($organization);
        $definition = SurveyDefinition::query()
            ->where('organization_id', $organization->getKey())
            ->where('definition_key', PlatformSurveyCatalog::HEALTH_KEY)
            ->firstOrFail();
        $published = $definition->activeVersion()->firstOrFail();
        $client = Client::factory()->forOrganization($organization)->create();
        $attempt = app(StartSurveyAttempt::class)->handle($client, $definition);
        $originalScoring = $published->scoring;

        $state = SurveyDefinitionFormMapper::denormalize($published);
        $state['rules'][0]['points'][0]['points'] = 9;
        $state['thresholds'][0]['label'] = 'Новый текст диапазона';
        $state['summary'] = 'Новое объяснение результата.';
        $state['result_metric_key'] = $state['metrics'][0]['key'];
        $state['result_road_map'] = 'Новый следующий шаг.';

        app(UpdateSurveyDefinitionDraft::class)->handle(
            $admin,
            $definition,
            SurveyDefinitionFormMapper::normalize($state),
        );

        $draft = $definition->refresh()->versions()->where('status', 'draft')->latest('version')->firstOrFail();
        self::assertSame(9, $draft->scoring['rules'][0]['points']['never']);
        self::assertSame('Новый текст диапазона', $draft->scoring['thresholds'][0]['label']['ru']);
        self::assertSame('Новое объяснение результата.', $draft->scoring['summary']['ru']);
        self::assertSame('Новый следующий шаг.', $draft->scoring['metrics'][0]['road_map']['ru']);

        app(PublishSurveyVersion::class)->handle($admin, $draft);

        $attempt->refresh();
        $publishedAgain = $definition->refresh()->activeVersion()->firstOrFail();
        self::assertNotSame($published->getKey(), $publishedAgain->getKey());
        self::assertSame($published->getKey(), $attempt->survey_version_id);
        self::assertSame($originalScoring, $published->fresh()->scoring);
        self::assertSame(9, $publishedAgain->scoring['rules'][0]['points']['never']);
        self::assertSame('Новый текст диапазона', $publishedAgain->scoring['thresholds'][0]['label']['ru']);
    }

    public function test_unknown_scoring_is_preserved_when_an_unrelated_edit_is_saved(): void
    {
        [$organization, $admin] = $this->fixture();
        $definition = SurveyDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'definition_key' => 'legacy-scoring',
            'title' => 'Старый тест',
            'title_en' => 'Legacy test',
            'is_available' => true,
        ]);
        $version = SurveyVersion::query()->create([
            'organization_id' => $organization->getKey(),
            'survey_definition_id' => $definition->getKey(),
            'version' => 1,
            'status' => 'published',
            'title' => 'Старый тест',
            'title_en' => 'Legacy test',
            'definition' => ['sections' => [[
                'key' => 'section',
                'title' => 'Раздел',
                'questions' => [[
                    'key' => 'boolean-question',
                    'type' => 'boolean',
                    'label' => 'Ответ',
                    'required' => true,
                ]],
            ]]],
            'scoring' => [
                'schema' => 'historical-v2',
                'calculation' => ['expression' => 'vendor-specific-expression'],
                'result_bands' => [['from' => 0, 'to' => 10, 'text' => 'Исторический результат']],
            ],
            'metric_schema_key' => 'legacy-scale',
            'source' => 'platform_default',
            'approval_status' => 'draft',
            'methodology' => 'legacy',
            'published_at' => now(),
        ]);
        $definition->forceFill(['active_version_id' => $version->getKey()])->save();
        $state = SurveyDefinitionFormMapper::denormalize($version);
        $state['title'] = 'Изменённое название';

        Livewire::actingAs($admin)
            ->test(EditSurveyDefinition::class, ['record' => $definition->getKey()])
            ->fillForm($state)
            ->call('save')
            ->assertHasNoFormErrors();

        $draft = $definition->refresh()->versions()->where('status', 'draft')->latest('version')->firstOrFail();
        self::assertSame($version->scoring, $draft->scoring);
        self::assertSame('Изменённое название', $draft->title);

        $mutatedState = SurveyDefinitionFormMapper::denormalize($draft);
        $mutatedState['sections'][0]['questions'][0]['label'] = 'Изменённый вопрос';

        Livewire::actingAs($admin)
            ->test(EditSurveyDefinition::class, ['record' => $definition->getKey()])
            ->fillForm($mutatedState)
            ->call('save')
            ->assertHasErrors();

        self::assertSame($draft->definition, $draft->fresh()->definition);
        self::assertSame($draft->scoring, $draft->fresh()->scoring);
    }

    public function test_legacy_scoring_hidden_state_cannot_replace_authoritative_scoring(): void
    {
        [$organization, $admin] = $this->fixture();
        $definition = SurveyDefinition::query()->create([
            'organization_id' => $organization->getKey(),
            'definition_key' => 'legacy-scoring-tamper',
            'title' => 'Старый тест',
            'is_available' => true,
        ]);
        $version = SurveyVersion::query()->create([
            'organization_id' => $organization->getKey(),
            'survey_definition_id' => $definition->getKey(),
            'version' => 1,
            'status' => 'published',
            'title' => 'Старый тест',
            'definition' => ['sections' => [[
                'key' => 'section',
                'title' => 'Раздел',
                'questions' => [[
                    'key' => 'boolean-question',
                    'type' => 'boolean',
                    'label' => 'Ответ',
                    'required' => true,
                ]],
            ]]],
            'scoring' => [
                'schema' => 'historical-v2',
                'calculation' => ['expression' => 'vendor-specific-expression'],
                'result_bands' => [['from' => 0, 'to' => 10, 'text' => 'Исторический результат']],
            ],
            'metric_schema_key' => 'legacy-scale',
            'source' => 'platform_default',
            'approval_status' => 'draft',
            'methodology' => 'legacy',
            'published_at' => now(),
        ]);
        $definition->forceFill(['active_version_id' => $version->getKey()])->save();
        $state = SurveyDefinitionFormMapper::denormalize($version);
        $state['legacy_scoring']['result_bands'][0]['text'] = 'Подмена';

        Livewire::actingAs($admin)
            ->test(EditSurveyDefinition::class, ['record' => $definition->getKey()])
            ->fillForm($state)
            ->call('save')
            ->assertHasErrors();

        self::assertSame(1, $definition->refresh()->versions()->count());
        self::assertSame('Исторический результат', $version->fresh()->scoring['result_bands'][0]['text']);
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

    /** @return array<string, mixed> */
    private function integerConditionState(SurveyVersion $version, string $operator, mixed $value): array
    {
        $state = SurveyDefinitionFormMapper::denormalize($version);
        $questions = [];
        foreach ($state['sections'][0]['questions'] as $question) {
            if (is_array($question) && is_string($question['key'] ?? null)) {
                $questions[$question['key']] = $question;
            }
        }

        $source = $questions['q-source'];
        $dependent = $questions['q-dependent'];
        $number = $questions['q-number'];
        $dependent['type'] = 'integer';
        $dependent['condition_question_key'] = 'q-number';
        $dependent['condition_operator'] = $operator;
        $dependent['condition_value'] = $value;
        $state['sections'][0]['questions'] = [$source, $number, $dependent];

        return $state;
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
