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
