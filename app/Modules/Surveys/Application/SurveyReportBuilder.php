<?php

namespace App\Modules\Surveys\Application;

use App\Modules\Surveys\Domain\Models\SurveyAttempt;
use App\Modules\Surveys\Domain\Models\SurveyComparison;
use App\Modules\Surveys\Domain\Models\SurveyDefinition;
use App\Modules\Surveys\Domain\Models\SurveyVersion;

final class SurveyReportBuilder
{
    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function handle(
        SurveyDefinition $definition,
        SurveyVersion $version,
        SurveyAttempt $attempt,
        array $result,
        ?SurveyComparison $comparison = null,
    ): array {
        $questions = $this->questions($attempt->definition_snapshot);
        $scoring = $attempt->scoring_snapshot;
        $metrics = [];

        foreach ($scoring['metrics'] ?? [] as $metric) {
            if (! is_array($metric) || ! is_string($metric['key'] ?? null)) {
                continue;
            }
            $key = $metric['key'];
            $rawValue = $result['metrics'][$key]['value'] ?? 0;
            $maxValue = is_numeric($metric['max_value'] ?? null) ? (float) $metric['max_value'] : null;
            $normalized = is_numeric($result['metrics'][$key]['normalized_score'] ?? null)
                ? (int) $result['metrics'][$key]['normalized_score']
                : $this->normalize($rawValue, $maxValue);
            $threshold = $this->threshold($key, (float) $rawValue, $scoring['thresholds'] ?? []);

            $metrics[$key] = [
                'key' => $key,
                'label' => $metric['label'] ?? $key,
                'raw_score' => (float) $rawValue,
                'score' => $normalized,
                'max_value' => $maxValue,
                'status' => $threshold['label'] ?? ['ru' => 'Стоит обратить внимание', 'en' => 'Worth observing'],
                'evidence' => $this->evidence($metric, $questions, $attempt->answers_snapshot ?? [], $scoring),
                'observation' => $metric['observation'] ?? ['ru' => 'Наблюдайте изменения самочувствия в динамике.', 'en' => 'Observe changes in your wellbeing over time.'],
                'reason' => $this->reason($metric['attention_reason'] ?? null, (float) $rawValue),
                'road_map' => $metric['road_map'] ?? ['ru' => 'Наблюдать эту зону без резких изменений нагрузки.', 'en' => 'Observe this area without making sudden changes to activity.'],
            ];
        }

        $ranked = array_values($metrics);
        usort($ranked, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $top = array_slice($ranked, 0, 3);
        $technicalMetrics = [];
        foreach ($metrics as $key => $metric) {
            $technicalMetrics[$key] = [
                'label' => $metric['label'],
                'value' => $metric['raw_score'],
                'normalized_score' => $metric['score'],
                'max_value' => $metric['max_value'],
            ];
        }

        return [
            'survey' => [
                'definition_key' => $definition->definition_key,
                'version' => $version->version,
                'title' => ['ru' => $version->title, 'en' => $version->title_en ?: $version->title],
                'methodology' => $version->methodology,
            ],
            'completed_at' => $attempt->completed_at?->toIso8601String(),
            'summary' => [
                'short' => [
                    'ru' => 'Результат помогает выбрать несколько направлений для спокойного наблюдения. Он не устанавливает диагноз.',
                    'en' => 'The result helps choose a few areas for calm observation. It does not establish a diagnosis.',
                ],
                'basis' => $scoring['summary'] ?? null,
            ],
            'attention_areas' => array_map(static fn (array $metric): array => [
                'label' => $metric['label'],
                'score' => $metric['score'],
                'status' => $metric['status'],
                'reason' => $metric['reason'],
                'evidence' => $metric['evidence'],
                'observation' => $metric['observation'],
            ], $top),
            'domains' => array_map(static fn (array $metric): array => [
                'label' => $metric['label'],
                'score' => $metric['score'],
                'raw_score' => $metric['raw_score'],
                'status' => $metric['status'],
            ], $ranked),
            'safe_steps' => $scoring['safe_steps'] ?? [],
            'specialist_questions' => $scoring['specialist_questions'] ?? [],
            'road_map' => [
                'title' => ['ru' => 'Road Map на ближайший этап', 'en' => 'Road Map for the next stage'],
                'description' => ['ru' => 'Небольшие безопасные шаги, которые можно наблюдать и обсуждать без подмены лечения.', 'en' => 'Small safe steps to observe and discuss without replacing treatment.'],
                'items' => array_merge(
                    array_map(static fn (array $metric): array => [
                        'title' => $metric['label'],
                        'description' => $metric['road_map'],
                        'category' => ['ru' => 'Наблюдение', 'en' => 'Observation'],
                    ], $top),
                    [[
                        'title' => ['ru' => 'Повторить тест и сравнить динамику', 'en' => 'Repeat the test and compare the trend'],
                        'description' => ['ru' => 'Вернитесь к опросу позже, чтобы увидеть изменения по тем же направлениям.', 'en' => 'Return to the questionnaire later to see changes in the same areas.'],
                        'category' => ['ru' => 'Динамика', 'en' => 'Trend'],
                    ]],
                ),
            ],
            'comparison' => $this->comparison($comparison, $metrics),
            'metrics' => $technicalMetrics,
            'thresholds' => $result['thresholds'] ?? [],
            'tags' => $result['tags'] ?? [],
            'disclaimer' => ['ru' => 'Опрос и результат носят информационный характер и не заменяют консультацию специалиста.', 'en' => 'This questionnaire and result are informational and do not replace professional advice.'],
            'ctas' => [
                'road_map' => ['ru' => 'Открыть Road Map', 'en' => 'Open Road Map'],
                'companion' => ['ru' => 'Открыть чат', 'en' => 'Open chat'],
                'repeat' => ['ru' => 'Пройти позже ещё раз', 'en' => 'Take it again later'],
            ],
        ];
    }

    private function normalize(mixed $value, ?float $maxValue): int
    {
        if (! is_numeric($value) || $maxValue === null || $maxValue <= 0) {
            return 0;
        }

        return max(0, min(100, (int) round(((float) $value / $maxValue) * 100)));
    }

    private function reason(mixed $reason, float $rawValue): mixed
    {
        if ($rawValue <= 0) {
            return [
                'ru' => 'По этим ответам выраженных жалоб в этой зоне не отмечено.',
                'en' => 'These answers did not indicate pronounced complaints in this area.',
            ];
        }

        return $reason ?? [
            'ru' => 'Эта зона выделена на основании ваших ответов.',
            'en' => 'This area was highlighted based on your answers.',
        ];
    }

    /** @return array<string, mixed>|null */
    private function threshold(string $metricKey, float $value, mixed $thresholds): ?array
    {
        if (! is_array($thresholds)) {
            return null;
        }

        foreach ($thresholds as $threshold) {
            if (! is_array($threshold) || ($threshold['metric_key'] ?? null) !== $metricKey) {
                continue;
            }
            if ((! isset($threshold['min']) || $value >= (float) $threshold['min'])
                && (! isset($threshold['max']) || $value <= (float) $threshold['max'])) {
                return $threshold;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, array<string, mixed>>
     */
    private function questions(array $definition): array
    {
        $questions = [];
        foreach ($definition['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                if (is_array($question) && is_string($question['key'] ?? null)) {
                    $questions[$question['key']] = $question;
                }
            }
        }

        return $questions;
    }

    /**
     * @param  array<string, mixed>  $metric
     * @param  array<string, array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     * @param  array<string, mixed>  $scoring
     * @return list<array<string, mixed>>
     */
    private function evidence(array $metric, array $questions, array $answers, array $scoring): array
    {
        $evidence = [];
        foreach ($metric['question_keys'] ?? [] as $questionKey) {
            $answer = $answers[$questionKey] ?? null;
            if ($answer === null || $answer === 'never' || $answer === '') {
                continue;
            }
            $question = $questions[$questionKey] ?? null;
            if (! is_array($question)) {
                continue;
            }
            $evidence[] = [
                'question' => $question['label'] ?? $questionKey,
                'answer' => $this->optionLabel($question, $answer),
                'score' => $this->answerScore($questionKey, $answer, $scoring),
            ];
            if (count($evidence) === 3) {
                break;
            }
        }

        return $evidence;
    }

    /** @param array<string, mixed> $question */
    private function optionLabel(array $question, mixed $answer): mixed
    {
        if (is_array($answer)) {
            return array_values(array_map(
                fn (mixed $selected): mixed => $this->optionLabel($question, $selected),
                $answer,
            ));
        }

        foreach ($question['options'] ?? [] as $option) {
            if (is_array($option) && ($option['value'] ?? null) === $answer) {
                return $option['label'] ?? $answer;
            }
        }

        return $answer;
    }

    /** @param array<string, mixed> $scoring */
    private function answerScore(string $questionKey, mixed $answer, array $scoring): int
    {
        if (is_string($answer) && is_numeric($scoring['answer_scale'][$answer] ?? null)) {
            return (int) $scoring['answer_scale'][$answer];
        }
        foreach ($scoring['rules'] ?? [] as $rule) {
            if (is_array($rule) && ($rule['question_key'] ?? null) === $questionKey && is_array($rule['points'] ?? null)) {
                if (is_array($answer)) {
                    return (int) array_sum(array_map(
                        static fn (mixed $selected): int => is_scalar($selected)
                            ? (int) ($rule['points'][(string) $selected] ?? 0)
                            : 0,
                        $answer,
                    ));
                }

                return (int) ($rule['points'][(string) $answer] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @param  array<string, array<string, mixed>>  $metrics
     * @return array<string, mixed>|null
     */
    private function comparison(?SurveyComparison $comparison, array $metrics): ?array
    {
        if ($comparison === null) {
            return null;
        }

        $items = [];
        foreach ($comparison->comparison_snapshot['metrics'] ?? [] as $key => $values) {
            if (! is_array($values) || ! isset($metrics[$key])) {
                continue;
            }
            $items[] = [
                'label' => $metrics[$key]['label'],
                'before' => (int) round((float) ($values['before'] ?? 0)),
                'after' => (int) round((float) ($values['after'] ?? 0)),
                'change' => (int) round((float) ($values['delta'] ?? 0)),
            ];
        }

        return [
            'message' => match ($comparison->status) {
                'improved' => ['ru' => 'По нескольким направлениям нагрузка стала ниже. Продолжайте в удобном темпе.', 'en' => 'Burden is lower in some areas. Keep going at a comfortable pace.'],
                'stagnation_detected' => ['ru' => 'Похоже, этот блок пока стоит на месте. Давай разберёмся, что получилось выполнять, а что оказалось неудобным.', 'en' => 'This area seems to be holding steady for now. Let’s look at what was practical and what was inconvenient.'],
                'changed' => ['ru' => 'Картина изменилась неодинаково по разным направлениям. Это хороший повод посмотреть на отдельные ответы.', 'en' => 'The picture changed differently across areas. It is worth looking at the individual answers.'],
                default => ['ru' => 'Эти два прохождения нельзя надёжно сопоставить.', 'en' => 'These two attempts cannot be reliably compared.'],
            },
            'items' => $items,
        ];
    }
}
