<?php

namespace Tests\Unit;

use App\Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SurveyDefinitionValidatorTest extends TestCase
{
    public function test_self_reference_is_rejected_before_the_current_question_is_registered(): void
    {
        $data = $this->data();
        $data['definition']['sections'][0]['questions'][1]['condition']['question_key'] = 'q-dependent';

        $this->expectException(ValidationException::class);
        (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
    }

    public function test_later_question_reference_is_rejected(): void
    {
        $data = $this->data();
        $data['definition']['sections'][0]['questions'][0]['condition'] = [
            'question_key' => 'q-dependent',
            'operator' => 'answered',
        ];

        $this->expectException(ValidationException::class);
        (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
    }

    public function test_missing_question_reference_is_rejected(): void
    {
        $data = $this->data();
        $data['definition']['sections'][0]['questions'][1]['condition']['question_key'] = 'missing';

        $this->expectException(ValidationException::class);
        (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
    }

    public function test_canonical_multiple_choice_condition_can_remain_valid_when_human_builder_does_not_expose_it(): void
    {
        $data = $this->data();
        $data['definition']['sections'][0]['questions'][0] = [
            'key' => 'q-multiple',
            'type' => 'multiple_choice',
            'label' => 'Варианты',
            'options' => [
                ['value' => 'one', 'label' => 'Один'],
                ['value' => 'two', 'label' => 'Два'],
            ],
            'condition' => null,
        ];
        $data['definition']['sections'][0]['questions'][1]['condition'] = [
            'question_key' => 'q-multiple',
            'operator' => 'equals',
            'value' => ['one'],
        ];
        $data['scoring']['rules'][0] = [
            'question_key' => 'q-multiple',
            'metric_key' => 'metric',
            'operator' => 'selected_sum',
            'points' => ['one' => 1, 'two' => 2],
        ];

        (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
        self::assertTrue(true);
    }

    public function test_fractional_integer_condition_is_rejected(): void
    {
        $data = $this->data();
        $data['definition']['sections'][0]['questions'][0]['type'] = 'integer';
        $data['definition']['sections'][0]['questions'][1]['condition']['value'] = 1.9;

        $this->expectException(ValidationException::class);
        (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
        self::assertTrue(true);
    }

    public function test_canonical_number_equality_remains_valid_for_legacy_builder_state(): void
    {
        $data = $this->data();
        $data['definition']['sections'][0]['questions'][0] = [
            'key' => 'q-number',
            'type' => 'number',
            'label' => 'Число',
            'condition' => null,
        ];
        $data['definition']['sections'][0]['questions'][1]['condition'] = [
            'question_key' => 'q-number',
            'operator' => 'equals',
            'value' => 5,
        ];
        $data['scoring']['rules'] = [[
            'question_key' => 'q-number',
            'metric_key' => 'metric',
            'operator' => 'numeric_value',
        ]];

        (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
        self::assertTrue(true);
    }

    public function test_duplicate_nested_identities_and_references_are_rejected(): void
    {
        $data = $this->data();
        $data['definition']['sections'][0]['questions'][0]['options'][] = ['value' => 'option-good', 'label' => 'Дубликат'];

        $this->expectException(ValidationException::class);
        (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
    }

    public function test_duplicate_section_question_metric_and_threshold_identities_are_rejected(): void
    {
        $cases = [
            function (): array {
                $data = $this->data();
                $data['definition']['sections'][] = $data['definition']['sections'][0];

                return $data;
            },
            function (): array {
                $data = $this->data();
                $data['definition']['sections'][0]['questions'][] = $data['definition']['sections'][0]['questions'][0];

                return $data;
            },
            function (): array {
                $data = $this->data();
                $data['scoring']['metrics'][] = $data['scoring']['metrics'][0];

                return $data;
            },
            function (): array {
                $data = $this->data();
                $data['scoring']['thresholds'][] = $data['scoring']['thresholds'][0];

                return $data;
            },
        ];

        foreach ($cases as $case) {
            $data = $case();
            try {
                (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
                self::fail('A duplicate nested identity was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_invalid_scoring_option_reference_is_rejected(): void
    {
        $data = $this->data();
        $data['scoring']['rules'][0]['points']['missing-option'] = 5;

        $this->expectException(ValidationException::class);
        (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
    }

    public function test_answer_scale_reference_to_deleted_option_is_rejected(): void
    {
        $data = $this->data();
        $data['scoring']['answer_scale'] = ['missing-option' => 1];

        try {
            (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
            self::fail('An answer scale reference to a missing option was accepted.');
        } catch (ValidationException $exception) {
            self::assertSame('Шкала содержит недоступный вариант ответа.', $exception->errors()['scoring.answer_scale'][0]);
        }
    }

    public function test_unsupported_scoring_type_can_be_preserved_only_in_legacy_mode(): void
    {
        $data = $this->data();
        $data['definition']['sections'][0]['questions'][0]['type'] = 'boolean';
        $data['definition']['sections'][0]['questions'][0]['options'] = [];
        $data['definition']['sections'][0]['questions'][1]['condition'] = null;

        try {
            (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
            self::fail('An unsupported scoring type was accepted as human configuration.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring'], true);
        self::assertTrue(true);
    }

    public function test_unknown_scoring_shape_can_be_preserved_in_legacy_mode_without_reinterpretation(): void
    {
        $data = $this->data();
        $data['scoring'] = [
            'schema' => 'historical-v2',
            'calculation' => ['expression' => 'vendor-specific-expression'],
            'result_bands' => [['from' => 0, 'to' => 10, 'text' => 'Исторический результат']],
        ];

        (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring'], true);
        self::assertTrue(true);
    }

    public function test_metric_without_a_rule_is_rejected_with_a_human_error(): void
    {
        $data = $this->data();
        $data['scoring']['rules'] = [];

        try {
            (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
            self::fail('A metric without a rule was accepted.');
        } catch (ValidationException $exception) {
            self::assertSame('Показатель должен содержать хотя бы одно правило.', $exception->errors()['scoring.metrics'][0]);
        }
    }

    public function test_missing_rich_scoring_question_reference_is_rejected_without_technical_details(): void
    {
        $data = $this->data();
        $data['scoring']['rules'][0]['question_key'] = 'missing-question';

        try {
            (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
            self::fail('A missing scoring question reference was accepted.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['scoring.rules'][0];
            self::assertSame('Правило подсчёта содержит недоступную ссылку.', $message);
            self::assertStringNotContainsString('missing-question', $message);
        }
    }

    public function test_overlapping_result_ranges_are_rejected(): void
    {
        $data = $this->data();
        $data['scoring']['thresholds'] = [
            ['metric_key' => 'metric', 'min' => 0, 'max' => 5, 'tag' => 'low', 'label' => 'Низкий'],
            ['metric_key' => 'metric', 'min' => 5, 'max' => 10, 'tag' => 'high', 'label' => 'Высокий'],
        ];

        try {
            (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
            self::fail('Overlapping result ranges were accepted.');
        } catch (ValidationException $exception) {
            self::assertSame('Диапазоны результата пересекаются.', $exception->errors()['scoring.thresholds'][0]);
        }
    }

    public function test_integer_result_range_gap_is_rejected(): void
    {
        $data = $this->data();
        $data['scoring']['thresholds'] = [
            ['metric_key' => 'metric', 'min' => 0, 'max' => 5, 'tag' => 'low', 'label' => 'Низкий'],
            ['metric_key' => 'metric', 'min' => 7, 'max' => 10, 'tag' => 'high', 'label' => 'Высокий'],
        ];

        try {
            (new SurveyDefinitionValidator)->validate($data['definition'], $data['scoring']);
            self::fail('A result range gap was accepted.');
        } catch (ValidationException $exception) {
            self::assertSame('Между диапазонами результата есть пропуск.', $exception->errors()['scoring.thresholds'][0]);
        }
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return [
            'definition' => [
                'sections' => [[
                    'key' => 'section',
                    'title' => 'Раздел',
                    'questions' => [
                        [
                            'key' => 'q-source',
                            'type' => 'single_choice',
                            'label' => 'Источник',
                            'required' => true,
                            'options' => [
                                ['value' => 'option-good', 'label' => 'Хорошо'],
                                ['value' => 'option-poor', 'label' => 'Плохо'],
                            ],
                        ],
                        [
                            'key' => 'q-dependent',
                            'type' => 'long_text',
                            'label' => 'Зависимый вопрос',
                            'condition' => ['question_key' => 'q-source', 'operator' => 'equals', 'value' => 'option-poor'],
                        ],
                    ],
                ]],
            ],
            'scoring' => [
                'metrics' => [['key' => 'metric', 'label' => 'Показатель']],
                'rules' => [['question_key' => 'q-source', 'metric_key' => 'metric', 'operator' => 'value_map', 'points' => ['option-good' => 1, 'option-poor' => 3]]],
                'thresholds' => [['metric_key' => 'metric', 'tag' => 'result', 'label' => 'Результат']],
                'comparison' => null,
            ],
        ];
    }
}
