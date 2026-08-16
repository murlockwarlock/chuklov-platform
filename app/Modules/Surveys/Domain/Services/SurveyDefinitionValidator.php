<?php

namespace App\Modules\Surveys\Domain\Services;

use Illuminate\Validation\ValidationException;

final class SurveyDefinitionValidator
{
    private const FIELD_TYPES = ['single_choice', 'multiple_choice', 'boolean', 'integer', 'number', 'short_text', 'long_text'];

    private const CONDITION_OPERATORS = ['equals', 'not_equals', 'in', 'not_in', 'answered', 'greater_than', 'less_than'];

    private const SCORING_OPERATORS = ['value_map', 'selected_sum', 'numeric_value'];

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scoring
     */
    public function validate(array $definition, array $scoring): void
    {
        $sections = $definition['sections'] ?? null;
        if (! is_array($sections) || $sections === []) {
            $this->fail('definition', 'Добавьте хотя бы один раздел.');
        }

        $questionKeys = [];
        foreach ($sections as $sectionIndex => $section) {
            if (! is_array($section) || ! $this->filledString($section['key'] ?? null) || ! is_array($section['questions'] ?? null)) {
                $this->fail("definition.sections.{$sectionIndex}", 'Раздел заполнен некорректно.');
            }
            if (! $this->localizedText($section['title'] ?? null)) {
                $this->fail("definition.sections.{$sectionIndex}.title", 'Укажите название раздела.');
            }
            foreach ($section['questions'] as $questionIndex => $question) {
                if (! is_array($question) || ! $this->filledString($question['key'] ?? null)) {
                    $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}", 'Укажите постоянный ключ вопроса.');
                }
                $key = $question['key'];
                if (isset($questionKeys[$key])) {
                    $this->fail('definition', "Ключ вопроса {$key} повторяется.");
                }
                $questionKeys[$key] = true;
                if (! in_array($question['type'] ?? null, self::FIELD_TYPES, true)) {
                    $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.type", 'Тип вопроса не поддерживается.');
                }
                if (! $this->localizedText($question['label'] ?? null)) {
                    $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.label", 'Укажите текст вопроса.');
                }
                $this->validateOptions($question, $sectionIndex, $questionIndex);
                $this->validateCondition($question['condition'] ?? null, $questionKeys, $sectionIndex, $questionIndex);
            }
        }

        $this->validateScoring($scoring, array_keys($questionKeys));
    }

    /** @param array<string, mixed> $question */
    private function validateOptions(array $question, int $sectionIndex, int $questionIndex): void
    {
        if (! in_array($question['type'], ['single_choice', 'multiple_choice'], true)) {
            return;
        }
        if (! is_array($question['options'] ?? null) || $question['options'] === []) {
            $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.options", 'Добавьте варианты ответа.');
        }
        foreach ($question['options'] as $option) {
            if (! is_array($option) || ! $this->filledString($option['value'] ?? null) || ! $this->localizedText($option['label'] ?? null)) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.options", 'Вариант ответа заполнен некорректно.');
            }
        }
    }

    /** @param array<string, bool> $knownQuestions */
    private function validateCondition(mixed $condition, array $knownQuestions, int $sectionIndex, int $questionIndex): void
    {
        if ($condition === null) {
            return;
        }
        if (! is_array($condition)
            || ! isset($knownQuestions[$condition['question_key'] ?? ''])
            || ! in_array($condition['operator'] ?? null, self::CONDITION_OPERATORS, true)) {
            $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition", 'Условие вопроса не поддерживается.');
        }
        $operator = $condition['operator'];
        $value = $condition['value'] ?? null;
        if (in_array($operator, ['in', 'not_in'], true) && (! is_array($value) || $value === [])) {
            $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Для этого условия укажите список значений.');
        }
        if (in_array($operator, ['greater_than', 'less_than'], true) && ! is_numeric($value)) {
            $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Для этого условия укажите число.');
        }
    }

    /**
     * @param  array<string, mixed>  $scoring
     * @param  list<string>  $questionKeys
     */
    private function validateScoring(array $scoring, array $questionKeys): void
    {
        if (! is_array($scoring['metrics'] ?? null)) {
            $this->fail('scoring.metrics', 'Настройте показатели результата.');
        }
        $metrics = [];
        foreach ($scoring['metrics'] as $metric) {
            if (! is_array($metric) || ! $this->filledString($metric['key'] ?? null) || ! $this->localizedText($metric['label'] ?? null)) {
                $this->fail('scoring.metrics', 'Показатель заполнен некорректно.');
            }
            if (isset($metrics[$metric['key']])) {
                $this->fail('scoring.metrics', 'Ключ показателя повторяется.');
            }
            $metrics[$metric['key']] = true;
        }
        foreach ($scoring['rules'] ?? [] as $rule) {
            if (! is_array($rule)
                || ! in_array($rule['question_key'] ?? null, $questionKeys, true)
                || ! isset($metrics[$rule['metric_key'] ?? ''])
                || ! in_array($rule['operator'] ?? null, self::SCORING_OPERATORS, true)) {
                $this->fail('scoring.rules', 'Правило подсчёта не поддерживается.');
            }
            if (in_array($rule['operator'], ['value_map', 'selected_sum'], true) && ! is_array($rule['points'] ?? null)) {
                $this->fail('scoring.rules', 'Для таблицы баллов укажите значения.');
            }
        }
        foreach ($scoring['thresholds'] ?? [] as $threshold) {
            if (! is_array($threshold) || ! isset($metrics[$threshold['metric_key'] ?? '']) || ! $this->filledString($threshold['tag'] ?? null) || ! $this->localizedText($threshold['label'] ?? null)) {
                $this->fail('scoring.thresholds', 'Порог результата заполнен некорректно.');
            }
        }
        $comparison = $scoring['comparison'] ?? null;
        if ($comparison !== null && (! is_array($comparison)
            || ($comparison['operator'] ?? null) !== 'no_decrease'
            || ! is_array($comparison['metric_keys'] ?? null)
            || array_diff($comparison['metric_keys'], array_keys($metrics)) !== [])) {
            $this->fail('scoring.comparison', 'Правило сравнения не поддерживается.');
        }
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function localizedText(mixed $value): bool
    {
        return $this->filledString($value)
            || (is_array($value) && ($this->filledString($value['ru'] ?? null) || $this->filledString($value['en'] ?? null)));
    }

    private function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
