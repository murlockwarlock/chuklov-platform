<?php

namespace Tests\Unit;

use App\Filament\Support\SurveyDefinitionFormMapper;
use App\Filament\Support\SurveyDefinitionFormOptions;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use PHPUnit\Framework\TestCase;

final class SurveyDefinitionFormMapperTest extends TestCase
{
    public function test_round_trip_preserves_nested_identities_and_references_when_labels_and_order_change(): void
    {
        $version = new SurveyVersion;
        $version->title = 'Тест';
        $version->title_en = 'Test';
        $version->description = 'Описание';
        $version->definition = $this->definition();
        $version->scoring = $this->scoring();
        $version->metric_schema_key = 'scale-v1';

        $state = SurveyDefinitionFormMapper::denormalize($version);
        $state['title'] = 'Изменённый тест';
        $state['sections'][0]['questions'][0]['label'] = 'Как вы спали?';
        [$source, $dependent, $number] = $state['sections'][0]['questions'];
        $state['sections'][0]['questions'] = [$source, $number, $dependent];

        $canonical = SurveyDefinitionFormMapper::normalize($state);
        $questions = $canonical['definition']['sections'][0]['questions'];

        self::assertSame('Изменённый тест', $canonical['title']);
        self::assertSame(['q-source', 'q-number', 'q-dependent'], array_column($questions, 'key'));
        self::assertSame('q-source', $questions[2]['condition']['question_key']);
        self::assertSame('option-poor', $questions[0]['options'][1]['value']);
        self::assertSame('metric-total', $canonical['scoring']['metrics'][0]['key']);
        self::assertSame('threshold-attention', $canonical['scoring']['thresholds'][0]['tag']);
    }

    public function test_new_identity_is_opaque_and_unique(): void
    {
        $identities = array_map(
            static fn (): string => SurveyDefinitionFormMapper::newIdentity(),
            range(1, 20),
        );

        self::assertCount(20, array_unique($identities));
        foreach ($identities as $identity) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f-]{27,}$/', $identity);
        }
    }

    public function test_human_selectors_expose_labels_while_returning_existing_values(): void
    {
        $options = SurveyDefinitionFormOptions::optionOptions($this->definition()['sections'], 'q-source');
        $operators = SurveyDefinitionFormOptions::conditionOperators('multiple_choice');

        self::assertSame('Хорошо', $options['option-good']);
        self::assertSame(['answered'], array_keys($operators));
        self::assertStringNotContainsString('option-good', implode(' ', $options));
        self::assertSame(['equals', 'not_equals', 'in', 'not_in', 'answered'], array_keys(SurveyDefinitionFormOptions::conditionOperators('single_choice')));
        self::assertSame(['equals', 'not_equals', 'answered'], array_keys(SurveyDefinitionFormOptions::conditionOperators('boolean')));
        self::assertSame(['greater_than', 'less_than', 'answered'], array_keys(SurveyDefinitionFormOptions::conditionOperators('number')));
        self::assertSame(['equals', 'not_equals', 'answered'], array_keys(SurveyDefinitionFormOptions::conditionOperators('short_text')));
        self::assertSame(['value_map'], array_keys(SurveyDefinitionFormOptions::scoringOperators('single_choice')));
        self::assertSame(['selected_sum'], array_keys(SurveyDefinitionFormOptions::scoringOperators('multiple_choice')));
        self::assertSame(['numeric_value'], array_keys(SurveyDefinitionFormOptions::scoringOperators('integer')));
    }

    public function test_condition_helper_only_warns_for_invalid_question_order_or_missing_source(): void
    {
        $sections = $this->definition()['sections'];

        self::assertNull(SurveyDefinitionFormOptions::conditionHelp($sections, 'q-dependent', 'q-source'));
        self::assertSame('Выберите вопрос выше, а не этот вопрос.', SurveyDefinitionFormOptions::conditionHelp($sections, 'q-dependent', 'q-dependent'));
        self::assertSame('Условие может ссылаться только на более ранний вопрос.', SurveyDefinitionFormOptions::conditionHelp($sections, 'q-dependent', 'q-number'));
        self::assertSame('Выбранный вопрос больше недоступен. Выберите другой вопрос или удалите условие.', SurveyDefinitionFormOptions::conditionHelp($sections, 'q-dependent', 'missing'));
    }

    public function test_legacy_number_equality_round_trips_without_numeric_type_conversion(): void
    {
        $version = new SurveyVersion;
        $definition = $this->definition();
        $definition['sections'][0]['questions'][2]['type'] = 'number';
        $definition['sections'][0]['questions'][] = [
            'key' => 'q-number-dependent',
            'type' => 'long_text',
            'label' => 'Пояснение',
            'condition' => ['question_key' => 'q-number', 'operator' => 'equals', 'value' => 5],
        ];
        $version->definition = $definition;
        $version->scoring = $this->scoring();

        $state = SurveyDefinitionFormMapper::denormalize($version);
        self::assertSame(['question_key' => 'q-number', 'operator' => 'equals', 'value' => 5], $state['sections'][0]['questions'][3]['condition_legacy']);

        $normalized = SurveyDefinitionFormMapper::normalize($state);
        self::assertSame(['question_key' => 'q-number', 'operator' => 'equals', 'value' => 5], $normalized['definition']['sections'][0]['questions'][3]['condition']);
    }

    public function test_unsupported_scoring_round_trips_without_conversion(): void
    {
        $version = new SurveyVersion;
        $version->definition = [
            'sections' => [[
                'key' => 'section',
                'title' => 'Раздел',
                'questions' => [['key' => 'boolean-question', 'type' => 'boolean', 'label' => 'Ответ', 'required' => true]],
            ]],
        ];
        $version->scoring = [
            'metrics' => [['key' => 'metric', 'label' => 'Показатель']],
            'rules' => [['question_key' => 'boolean-question', 'metric_key' => 'metric', 'operator' => 'value_map', 'points' => ['true' => 1]]],
            'thresholds' => [],
            'comparison' => null,
        ];

        $state = SurveyDefinitionFormMapper::denormalize($version);

        self::assertSame($version->scoring, $state['legacy_scoring']);
        self::assertSame($version->scoring, SurveyDefinitionFormMapper::normalize($state)['scoring']);
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return [
            'sections' => [[
                'key' => 'section-main',
                'title' => 'Раздел',
                'questions' => [
                    [
                        'key' => 'q-source',
                        'type' => 'single_choice',
                        'label' => 'Как спали',
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
        ];
    }

    /** @return array<string, mixed> */
    private function scoring(): array
    {
        return [
            'metrics' => [['key' => 'metric-total', 'label' => 'Итог']],
            'rules' => [
                ['question_key' => 'q-source', 'metric_key' => 'metric-total', 'operator' => 'value_map', 'points' => ['option-good' => 1, 'option-poor' => 3]],
                ['question_key' => 'q-number', 'metric_key' => 'metric-total', 'operator' => 'numeric_value'],
            ],
            'thresholds' => [['metric_key' => 'metric-total', 'min' => 3, 'tag' => 'threshold-attention', 'label' => 'Нужно внимание']],
            'comparison' => ['operator' => 'no_decrease', 'metric_keys' => ['metric-total']],
        ];
    }
}
