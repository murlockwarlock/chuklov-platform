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
}
