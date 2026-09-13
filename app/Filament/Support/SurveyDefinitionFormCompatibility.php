<?php

namespace App\Filament\Support;

final class SurveyDefinitionFormCompatibility
{
    private const HUMAN_SCORING_FIELDS = [
        'answer_scale',
        'metrics',
        'rules',
        'thresholds',
        'comparison',
        'summary',
        'safe_steps',
        'specialist_questions',
    ];

    private const HUMAN_METRIC_FIELDS = [
        'key',
        'label',
        'max_value',
        'normalization',
        'question_keys',
        'attention_reason',
        'observation',
        'road_map',
    ];

    private const HUMAN_RULE_FIELDS = ['question_key', 'metric_key', 'operator', 'points', 'multiplier'];

    private const HUMAN_THRESHOLD_FIELDS = ['metric_key', 'min', 'max', 'tag', 'label'];

    private const HUMAN_COMPARISON_FIELDS = ['operator', 'metric_keys', 'basis'];

    private const HUMAN_NORMALIZATIONS = ['symptom_burden_0_100'];

    private const HUMAN_COMPARISON_BASES = ['normalized_score'];

    /**
     * @param  array<int|string, mixed>  $sections
     * @return array<string, string>
     */
    public static function questionTypes(array $sections): array
    {
        $types = [];
        foreach (self::orderedQuestions($sections) as $question) {
            if (is_string($question['key'] ?? null) && is_string($question['type'] ?? null)) {
                $types[$question['key']] = $question['type'];
            }
        }

        return $types;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, string>  $questionTypes
     */
    public static function isHumanCondition(array $condition, ?string $sourceType, array $questionTypes): bool
    {
        $sourceKey = $condition['question_key'] ?? null;
        $operator = $condition['operator'] ?? null;
        if (! is_string($sourceKey) || ! array_key_exists($sourceKey, $questionTypes) || ! is_string($operator)) {
            return false;
        }
        if (! array_key_exists($operator, SurveyDefinitionFormOptions::conditionOperators($sourceType))) {
            return false;
        }
        if ($operator === 'answered') {
            return ! array_key_exists('value', $condition) || $condition['value'] === null;
        }

        $value = $condition['value'] ?? null;

        return match ($sourceType) {
            'single_choice' => $operator === 'equals' || $operator === 'not_equals'
                ? is_string($value)
                : is_array($value) && $value !== [] && count(array_filter($value, 'is_string')) === count($value),
            'boolean' => in_array($operator, ['equals', 'not_equals'], true) && is_bool($value),
            'integer' => in_array($operator, ['equals', 'not_equals', 'greater_than', 'less_than'], true) && is_int($value),
            'number' => in_array($operator, ['greater_than', 'less_than'], true) && is_numeric($value),
            'short_text', 'long_text' => in_array($operator, ['equals', 'not_equals'], true) && is_string($value),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scoring
     */
    public static function isHumanScoring(array $definition, array $scoring): bool
    {
        if (array_diff(array_keys($scoring), self::HUMAN_SCORING_FIELDS) !== []) {
            return false;
        }

        $sections = is_array($definition['sections'] ?? null) ? $definition['sections'] : [];
        $types = self::questionTypes($sections);

        if (array_key_exists('answer_scale', $scoring) && ! self::isHumanAnswerScale($scoring['answer_scale'])) {
            return false;
        }

        $metricKeys = [];
        foreach (is_array($scoring['metrics'] ?? null) ? $scoring['metrics'] : [] as $metric) {
            if (! is_array($metric)
                || array_diff(array_keys($metric), self::HUMAN_METRIC_FIELDS) !== []
                || ! is_string($metric['key'] ?? null)
                || ! self::isHumanLabel($metric['label'] ?? null)
                || (array_key_exists('max_value', $metric) && ! self::isHumanNumberOrNull($metric['max_value']))
                || (array_key_exists('normalization', $metric) && ! self::isHumanNormalization($metric['normalization']))
                || (array_key_exists('question_keys', $metric) && ! self::isHumanStringList($metric['question_keys']))
                || ! self::isHumanMetricText($metric, 'attention_reason')
                || ! self::isHumanMetricText($metric, 'observation')
                || ! self::isHumanMetricText($metric, 'road_map')) {
                return false;
            }
            $metricKeys[$metric['key']] = true;
        }
        foreach (is_array($scoring['rules'] ?? null) ? $scoring['rules'] : [] as $rule) {
            if (! is_array($rule)
                || array_diff(array_keys($rule), self::HUMAN_RULE_FIELDS) !== []
                || ! is_string($rule['question_key'] ?? null)
                || ! is_string($rule['metric_key'] ?? null)) {
                return false;
            }
            $type = $types[$rule['question_key']] ?? null;
            $operator = $rule['operator'] ?? null;
            if (! is_string($operator) || ! in_array($operator, ['value_map', 'selected_sum', 'numeric_value'], true)) {
                return false;
            }
            if ($type !== null && ! array_key_exists($operator, SurveyDefinitionFormOptions::scoringOperators($type))) {
                return false;
            }
            if (in_array($operator, ['value_map', 'selected_sum'], true) && ! is_array($rule['points'] ?? null)) {
                return false;
            }
            if (in_array($operator, ['value_map', 'selected_sum'], true)) {
                foreach ($rule['points'] as $points) {
                    if (! self::isHumanNumber($points)) {
                        return false;
                    }
                }
            }
            if ($operator === 'numeric_value' && array_key_exists('multiplier', $rule) && ! self::isHumanNumberOrNull($rule['multiplier'])) {
                return false;
            }
        }
        foreach (is_array($scoring['thresholds'] ?? null) ? $scoring['thresholds'] : [] as $threshold) {
            if (! is_array($threshold)
                || array_diff(array_keys($threshold), self::HUMAN_THRESHOLD_FIELDS) !== []
                || ! is_string($threshold['metric_key'] ?? null)
                || ! is_string($threshold['tag'] ?? null)
                || ! self::isHumanLabel($threshold['label'] ?? null)
                || (array_key_exists('min', $threshold) && ! self::isHumanNumberOrNull($threshold['min']))
                || (array_key_exists('max', $threshold) && ! self::isHumanNumberOrNull($threshold['max']))) {
                return false;
            }
        }

        foreach (['summary'] as $key) {
            if (array_key_exists($key, $scoring) && ! self::isHumanLabel($scoring[$key])) {
                return false;
            }
        }
        foreach (['safe_steps', 'specialist_questions'] as $key) {
            if (array_key_exists($key, $scoring) && ! self::isHumanLabelList($scoring[$key])) {
                return false;
            }
        }

        $comparison = $scoring['comparison'] ?? null;
        if ($comparison !== null) {
            if (! is_array($comparison)
                || array_diff(array_keys($comparison), self::HUMAN_COMPARISON_FIELDS) !== []
                || ($comparison['operator'] ?? null) !== 'no_decrease'
                || ! is_array($comparison['metric_keys'] ?? null)
                || $comparison['metric_keys'] === []
                || count(array_filter($comparison['metric_keys'], 'is_string')) !== count($comparison['metric_keys'])
                || (array_key_exists('basis', $comparison) && ! in_array($comparison['basis'], self::HUMAN_COMPARISON_BASES, true))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int|string, mixed>  $sections
     * @return list<array<string, mixed>>
     */
    private static function orderedQuestions(array $sections): array
    {
        $questions = [];
        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach (is_array($section['questions'] ?? null) ? $section['questions'] : [] as $question) {
                if (is_array($question)) {
                    $questions[] = $question;
                }
            }
        }

        return $questions;
    }

    private static function isHumanLabel(mixed $value): bool
    {
        return is_string($value)
            || (is_array($value)
                && array_diff(array_keys($value), ['ru', 'en']) === []
                && (is_string($value['ru'] ?? null) || is_string($value['en'] ?? null)));
    }

    private static function isHumanMetricText(array $metric, string $key): bool
    {
        return ! array_key_exists($key, $metric) || $metric[$key] === null || self::isHumanLabel($metric[$key]);
    }

    private static function isHumanAnswerScale(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $answer => $points) {
            if (! is_string($answer) || $answer === '' || ! self::isHumanNumber($points)) {
                return false;
            }
        }

        return $value !== [];
    }

    private static function isHumanNormalization(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::HUMAN_NORMALIZATIONS, true);
    }

    private static function isHumanNumberOrNull(mixed $value): bool
    {
        return $value === null || self::isHumanNumber($value);
    }

    private static function isHumanNumber(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value);
    }

    private static function isHumanStringList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && count(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== '')) === count($value);
    }

    private static function isHumanLabelList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && count(array_filter($value, self::isHumanLabel(...))) === count($value);
    }
}
