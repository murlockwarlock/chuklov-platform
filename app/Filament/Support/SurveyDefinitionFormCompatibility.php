<?php

namespace App\Filament\Support;

final class SurveyDefinitionFormCompatibility
{
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
            'number' => in_array($operator, ['equals', 'not_equals', 'greater_than', 'less_than'], true) && is_numeric($value),
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
        if (array_diff(array_keys($scoring), ['metrics', 'rules', 'thresholds', 'comparison']) !== []) {
            return false;
        }
        $sections = is_array($definition['sections'] ?? null) ? $definition['sections'] : [];
        $types = self::questionTypes($sections);
        $metricKeys = [];
        foreach (is_array($scoring['metrics'] ?? null) ? $scoring['metrics'] : [] as $metric) {
            if (! is_array($metric)
                || array_diff(array_keys($metric), ['key', 'label']) !== []
                || ! is_string($metric['key'] ?? null)
                || ! self::isHumanLabel($metric['label'] ?? null)) {
                return false;
            }
            $metricKeys[$metric['key']] = true;
        }
        foreach (is_array($scoring['rules'] ?? null) ? $scoring['rules'] : [] as $rule) {
            if (! is_array($rule) || ! isset($types[$rule['question_key'] ?? '']) || ! isset($metricKeys[$rule['metric_key'] ?? ''])) {
                return false;
            }
            $type = $types[$rule['question_key']];
            $operator = $rule['operator'] ?? null;
            if (! is_string($operator) || ! array_key_exists($operator, SurveyDefinitionFormOptions::scoringOperators($type)) || array_diff(array_keys($rule), ['question_key', 'metric_key', 'operator', 'points', 'multiplier']) !== []) {
                return false;
            }
            if (in_array($operator, ['value_map', 'selected_sum'], true) && ! is_array($rule['points'] ?? null)) {
                return false;
            }
            if (in_array($operator, ['value_map', 'selected_sum'], true)) {
                foreach ($rule['points'] as $points) {
                    if (! is_numeric($points)) {
                        return false;
                    }
                }
            }
            if ($operator === 'numeric_value' && isset($rule['multiplier']) && ! is_numeric($rule['multiplier'])) {
                return false;
            }
        }
        foreach (is_array($scoring['thresholds'] ?? null) ? $scoring['thresholds'] : [] as $threshold) {
            if (! is_array($threshold)
                || array_diff(array_keys($threshold), ['metric_key', 'min', 'max', 'tag', 'label']) !== []
                || ! is_string($threshold['metric_key'] ?? null)
                || ! isset($metricKeys[$threshold['metric_key']])
                || ! is_string($threshold['tag'] ?? null)
                || ! self::isHumanLabel($threshold['label'] ?? null)
                || (isset($threshold['min']) && ! is_numeric($threshold['min']))
                || (isset($threshold['max']) && ! is_numeric($threshold['max']))) {
                return false;
            }
        }
        $comparison = $scoring['comparison'] ?? null;
        if ($comparison !== null) {
            if (! is_array($comparison)
                || array_diff(array_keys($comparison), ['operator', 'metric_keys']) !== []
                || ($comparison['operator'] ?? null) !== 'no_decrease'
                || ! is_array($comparison['metric_keys'] ?? null)
                || $comparison['metric_keys'] === []
                || count(array_filter($comparison['metric_keys'], 'is_string')) !== count($comparison['metric_keys'])
                || array_diff($comparison['metric_keys'], array_keys($metricKeys)) !== []) {
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
            || (is_array($value) && (is_string($value['ru'] ?? null) || is_string($value['en'] ?? null)));
    }
}
