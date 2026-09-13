<?php

namespace Tests\Feature;

use App\Modules\Surveys\Application\SurveyReportBuilder;
use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;
use Tests\TestCase;

final class SurveyReportBuilderTest extends TestCase
{
    public function test_multiple_choice_evidence_is_rendered_and_scored(): void
    {
        $definition = new SurveyDefinition;
        $definition->forceFill(['definition_key' => 'multiple-choice-report']);
        $version = new SurveyVersion;
        $version->forceFill(['version' => 1, 'title' => 'Контрольный тест']);
        $attempt = new SurveyAttempt;
        $attempt->forceFill([
            'definition_snapshot' => [
                'sections' => [[
                    'questions' => [[
                        'key' => 'symptoms',
                        'type' => 'multiple_choice',
                        'label' => 'Что беспокоит?',
                        'options' => [
                            ['value' => 'neck', 'label' => 'Шея'],
                            ['value' => 'back', 'label' => 'Спина'],
                        ],
                    ]],
                ]],
            ],
            'scoring_snapshot' => [
                'metrics' => [[
                    'key' => 'burden',
                    'label' => 'Общая нагрузка',
                    'max_value' => 10,
                    'question_keys' => ['symptoms'],
                ]],
                'rules' => [[
                    'question_key' => 'symptoms',
                    'metric_key' => 'burden',
                    'operator' => 'selected_sum',
                    'points' => ['neck' => 2, 'back' => 3],
                ]],
                'thresholds' => [[
                    'metric_key' => 'burden',
                    'min' => 1,
                    'tag' => 'attention',
                    'label' => 'Стоит обратить внимание',
                ]],
            ],
            'answers_snapshot' => ['symptoms' => ['neck', 'back']],
        ]);
        $result = [
            'metrics' => ['burden' => ['value' => 5, 'normalized_score' => 50]],
            'thresholds' => [['metric_key' => 'burden', 'tag' => 'attention', 'label' => 'Стоит обратить внимание']],
            'tags' => ['attention'],
        ];

        $report = app(SurveyReportBuilder::class)->handle($definition, $version, $attempt, $result);
        $evidence = $report['attention_areas'][0]['evidence'][0];

        self::assertSame(['Шея', 'Спина'], $evidence['answer']);
        self::assertSame(5, $evidence['score']);
    }

    public function test_evidence_uses_rule_points_and_membership_instead_of_compatibility_fields(): void
    {
        $definition = new SurveyDefinition;
        $definition->forceFill(['definition_key' => 'authoritative-scoring-report']);
        $version = new SurveyVersion;
        $version->forceFill(['version' => 1, 'title' => 'Контрольный тест']);
        $attempt = new SurveyAttempt;
        $attempt->forceFill([
            'definition_snapshot' => [
                'sections' => [[
                    'questions' => [
                        [
                            'key' => 'target-question',
                            'type' => 'single_choice',
                            'label' => 'Как часто?',
                            'options' => [
                                ['value' => 'rarely', 'label' => 'Редко'],
                            ],
                        ],
                        [
                            'key' => 'stale-question',
                            'type' => 'single_choice',
                            'label' => 'Старый вопрос',
                            'options' => [
                                ['value' => 'rarely', 'label' => 'Редко'],
                            ],
                        ],
                    ],
                ]],
            ],
            'scoring_snapshot' => [
                'answer_scale' => ['rarely' => 1],
                'metrics' => [[
                    'key' => 'burden',
                    'label' => 'Общая нагрузка',
                    'max_value' => 10,
                    'question_keys' => ['stale-question'],
                ]],
                'rules' => [[
                    'question_key' => 'target-question',
                    'metric_key' => 'burden',
                    'operator' => 'value_map',
                    'points' => ['rarely' => 2],
                ]],
                'thresholds' => [[
                    'metric_key' => 'burden',
                    'min' => 1,
                    'tag' => 'attention',
                    'label' => 'Стоит обратить внимание',
                ]],
            ],
            'answers_snapshot' => ['target-question' => 'rarely'],
        ]);
        $result = [
            'metrics' => ['burden' => ['value' => 2, 'normalized_score' => 20]],
            'thresholds' => [['metric_key' => 'burden', 'tag' => 'attention', 'label' => 'Стоит обратить внимание']],
            'tags' => ['attention'],
        ];

        $report = app(SurveyReportBuilder::class)->handle($definition, $version, $attempt, $result);
        $metric = $report['metrics']['burden'];
        $attentionArea = $report['attention_areas'][0];

        self::assertSame(2.0, $metric['value']);
        self::assertSame('Как часто?', $attentionArea['evidence'][0]['question']);
        self::assertSame(2, $attentionArea['evidence'][0]['score']);
    }
}
