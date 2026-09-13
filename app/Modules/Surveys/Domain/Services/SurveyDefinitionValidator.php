<?php

namespace App\Modules\Surveys\Domain\Services;

use Illuminate\Validation\ValidationException;

final class SurveyDefinitionValidator
{
    private const FIELD_TYPES = ['single_choice', 'multiple_choice', 'boolean', 'integer', 'number', 'short_text', 'long_text'];

    private const SCORING_OPERATORS = ['value_map', 'selected_sum', 'numeric_value'];

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scoring
     */
    public function validate(array $definition, array $scoring, bool $allowUnsupportedScoring = false): void
    {
        $sections = $definition['sections'] ?? null;
        if (! is_array($sections) || $sections === []) {
            $this->fail('definition', 'Добавьте хотя бы один раздел.');
        }

        $sectionKeys = [];
        $knownQuestions = [];
        foreach ($sections as $sectionIndex => $section) {
            if (! is_array($section) || ! $this->filledString($section['key'] ?? null) || ! is_array($section['questions'] ?? null) || $section['questions'] === []) {
                $this->fail("definition.sections.{$sectionIndex}", 'Раздел заполнен некорректно.');
            }
            $sectionKey = $section['key'];
            if (isset($sectionKeys[$sectionKey])) {
                $this->fail('definition.sections', 'Разделы должны быть уникальными.');
            }
            $sectionKeys[$sectionKey] = true;
            if (! $this->localizedText($section['title'] ?? null)) {
                $this->fail("definition.sections.{$sectionIndex}.title", 'Укажите название раздела.');
            }

            foreach ($section['questions'] as $questionIndex => $question) {
                if (! is_array($question) || ! $this->filledString($question['key'] ?? null)) {
                    $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}", 'Не удалось сохранить вопрос. Откройте форму заново.');
                }
                $key = $question['key'];
                if (isset($knownQuestions[$key])) {
                    $this->fail('definition.questions', 'Вопросы должны быть уникальными.');
                }
                $type = $question['type'] ?? null;
                if (! is_string($type) || ! in_array($type, self::FIELD_TYPES, true)) {
                    $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.type", 'Тип вопроса не поддерживается.');
                }
                if (! $this->localizedText($question['label'] ?? null)) {
                    $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.label", 'Укажите текст вопроса.');
                }

                $this->validateOptions($question, $sectionIndex, $questionIndex);
                $this->validateCondition($question['condition'] ?? null, $knownQuestions, $sectionIndex, $questionIndex);
                $knownQuestions[$key] = [
                    'type' => $type,
                    'options' => $question['options'] ?? [],
                ];
            }
        }

        if (! $allowUnsupportedScoring) {
            $this->validateScoring($scoring, $knownQuestions);
        }
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

        $values = [];
        foreach ($question['options'] as $option) {
            if (! is_array($option) || ! $this->filledString($option['value'] ?? null) || ! $this->localizedText($option['label'] ?? null)) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.options", 'Вариант ответа заполнен некорректно.');
            }
            if (isset($values[$option['value']])) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.options", 'Варианты ответа должны быть уникальными.');
            }
            $values[$option['value']] = true;
        }
    }

    /** @param array<string, array{type: string, options: mixed}> $knownQuestions */
    private function validateCondition(mixed $condition, array $knownQuestions, int $sectionIndex, int $questionIndex): void
    {
        if ($condition === null) {
            return;
        }
        if (! is_array($condition)) {
            $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition", 'Условие показа заполнено некорректно.');
        }

        $sourceKey = $condition['question_key'] ?? null;
        if (! is_string($sourceKey) || ! isset($knownQuestions[$sourceKey])) {
            $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition", 'Условие должно ссылаться на предыдущий вопрос.');
        }
        $source = $knownQuestions[$sourceKey];
        $operator = $condition['operator'] ?? null;
        if (! is_string($operator) || ! in_array($operator, array_keys($this->canonicalConditionOperators($source['type'])), true)) {
            $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition", 'Такое условие показа недоступно для выбранного типа ответа.');
        }

        if ($operator === 'answered') {
            if (array_key_exists('value', $condition) && $condition['value'] !== null) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Для условия «Есть ответ» значение не требуется.');
            }

            return;
        }

        $value = $condition['value'] ?? null;
        if (in_array($operator, ['equals', 'not_equals'], true)) {
            if ($source['type'] === 'single_choice' && ! $this->choiceValue($value, $this->optionValues($source['options']))) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Выберите существующий вариант ответа.');
            }
            if ($source['type'] === 'multiple_choice' && ! $this->multipleChoiceValue($value, $this->optionValues($source['options']))) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Выберите существующие варианты ответа.');
            }
            if ($source['type'] === 'boolean' && ! is_bool($value)) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Выберите ответ «Да» или «Нет».');
            }
            if ($source['type'] === 'integer' && ! is_int($value)) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Для этого условия укажите целое число.');
            }
            if ($source['type'] === 'number' && ! is_numeric($value)) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Для этого условия укажите число.');
            }
            if (in_array($source['type'], ['short_text', 'long_text'], true) && ! is_string($value)) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Для этого условия укажите текст.');
            }
        }
        if (in_array($operator, ['in', 'not_in'], true)) {
            if (! is_array($value) || $value === [] || ! array_is_list($value)) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Для этого условия укажите список значений.');
            }
            $allowedValues = $this->optionValues($source['options']);
            $valid = match ($source['type']) {
                'single_choice' => $this->choiceList($value, $allowedValues),
                'multiple_choice' => $this->multipleChoiceValue($value, $allowedValues),
                'boolean' => count(array_filter($value, 'is_bool')) === count($value),
                'integer' => count(array_filter($value, 'is_int')) === count($value),
                'number' => count(array_filter($value, 'is_numeric')) === count($value),
                'short_text', 'long_text' => count(array_filter($value, 'is_string')) === count($value),
                default => false,
            };
            if (! $valid) {
                $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Значения условия заполнены некорректно.');
            }
        }
        if (in_array($operator, ['greater_than', 'less_than'], true) && ! is_numeric($value)) {
            $this->fail("definition.sections.{$sectionIndex}.questions.{$questionIndex}.condition.value", 'Для этого условия укажите число.');
        }
    }

    /**
     * @param  array<string, mixed>  $scoring
     * @param  array<string, array{type: string, options: mixed}>  $knownQuestions
     */
    private function validateScoring(array $scoring, array $knownQuestions): void
    {
        if (array_key_exists('answer_scale', $scoring)) {
            $this->validateAnswerScale($scoring['answer_scale'], $knownQuestions);
        }
        if (! is_array($scoring['metrics'] ?? null) || $scoring['metrics'] === []) {
            $this->fail('scoring.metrics', 'Настройте показатели результата.');
        }

        $metrics = [];
        foreach ($scoring['metrics'] as $metric) {
            if (! is_array($metric) || ! $this->filledString($metric['key'] ?? null) || ! $this->localizedText($metric['label'] ?? null)) {
                $this->fail('scoring.metrics', 'Показатель заполнен некорректно.');
            }
            if (isset($metrics[$metric['key']])) {
                $this->fail('scoring.metrics', 'Показатели должны быть уникальными.');
            }
            if (array_key_exists('max_value', $metric) && $metric['max_value'] !== null && (! is_numeric($metric['max_value']) || ! is_finite((float) $metric['max_value']))) {
                $this->fail('scoring.metrics', 'Максимальный результат показателя должен быть числом.');
            }
            if (array_key_exists('normalization', $metric) && $metric['normalization'] !== null && ! $this->filledString($metric['normalization'])) {
                $this->fail('scoring.metrics', 'Способ нормализации показателя заполнен некорректно.');
            }
            if (array_key_exists('question_keys', $metric)) {
                if (! is_array($metric['question_keys']) || ! array_is_list($metric['question_keys']) || $metric['question_keys'] === []) {
                    $this->fail('scoring.metrics', 'Добавьте хотя бы один вопрос в показатель.');
                }
                foreach ($metric['question_keys'] as $questionKey) {
                    if (! is_string($questionKey) || ! isset($knownQuestions[$questionKey])) {
                        $this->fail('scoring.metrics', 'Показатель содержит недоступную ссылку на вопрос.');
                    }
                }
            }
            foreach (['attention_reason', 'observation', 'road_map'] as $textKey) {
                if (array_key_exists($textKey, $metric) && $metric[$textKey] !== null && ! $this->localizedText($metric[$textKey])) {
                    $this->fail('scoring.metrics', 'Текст показателя заполнен некорректно.');
                }
            }
            $metrics[$metric['key']] = true;
        }

        $rules = $scoring['rules'] ?? [];
        if (! is_array($rules)) {
            $this->fail('scoring.rules', 'Правила подсчёта заполнены некорректно.');
        }
        $rulesByMetric = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)
                || ! is_string($rule['question_key'] ?? null)
                || ! isset($knownQuestions[$rule['question_key']])
                || ! is_string($rule['metric_key'] ?? null)
                || ! isset($metrics[$rule['metric_key']])
                || ! is_string($rule['operator'] ?? null)
                || ! in_array($rule['operator'], self::SCORING_OPERATORS, true)) {
                $this->fail('scoring.rules', 'Правило подсчёта содержит недоступную ссылку.');
            }

            if (in_array($rule['operator'], ['value_map', 'selected_sum'], true)) {
                if (! is_array($rule['points'] ?? null) || $rule['points'] === []) {
                    $this->fail('scoring.rules', 'Для правила подсчёта укажите баллы за варианты.');
                }
                $expectedType = $rule['operator'] === 'value_map' ? 'single_choice' : 'multiple_choice';
                $question = $knownQuestions[$rule['question_key']];
                if ($question['type'] !== $expectedType) {
                    $this->fail('scoring.rules', 'Правило подсчёта недоступно для выбранного типа вопроса.');
                }
                $allowedValues = $this->optionValues($question['options']);
                foreach (array_keys($rule['points']) as $value) {
                    if (! in_array((string) $value, $allowedValues, true)) {
                        $this->fail('scoring.rules', 'Правило подсчёта содержит недоступный вариант ответа.');
                    }
                }
            }
            if ($rule['operator'] === 'numeric_value' && isset($rule['multiplier']) && ! is_numeric($rule['multiplier'])) {
                $this->fail('scoring.rules', 'Множитель должен быть числом.');
            }
            $rulesByMetric[$rule['metric_key']] = true;
        }
        foreach (array_keys($metrics) as $metricKey) {
            if (! isset($rulesByMetric[$metricKey])) {
                $this->fail('scoring.metrics', 'Показатель должен содержать хотя бы одно правило.');
            }
        }

        $thresholds = $scoring['thresholds'] ?? [];
        if (! is_array($thresholds)) {
            $this->fail('scoring.thresholds', 'Пороговые результаты заполнены некорректно.');
        }
        $tags = [];
        $thresholdsByMetric = [];
        foreach ($thresholds as $threshold) {
            if (! is_array($threshold)
                || ! is_string($threshold['metric_key'] ?? null)
                || ! isset($metrics[$threshold['metric_key']])
                || ! $this->filledString($threshold['tag'] ?? null)
                || ! $this->localizedText($threshold['label'] ?? null)) {
                $this->fail('scoring.thresholds', 'Пороговый результат заполнен некорректно.');
            }
            if (isset($tags[$threshold['tag']])) {
                $this->fail('scoring.thresholds', 'Пороговые результаты должны быть уникальными.');
            }
            $tags[$threshold['tag']] = true;
            if (isset($threshold['min']) && ! is_numeric($threshold['min'])) {
                $this->fail('scoring.thresholds', 'Минимум порога должен быть числом.');
            }
            if (isset($threshold['max']) && ! is_numeric($threshold['max'])) {
                $this->fail('scoring.thresholds', 'Максимум порога должен быть числом.');
            }
            if (isset($threshold['min'], $threshold['max']) && (float) $threshold['min'] > (float) $threshold['max']) {
                $this->fail('scoring.thresholds', 'Минимум порога не может быть больше максимума.');
            }
            $thresholdsByMetric[$threshold['metric_key']][] = $threshold;
        }
        $this->validateThresholdRanges($thresholdsByMetric);

        foreach (['summary'] as $key) {
            if (array_key_exists($key, $scoring) && ! $this->localizedText($scoring[$key])) {
                $this->fail('scoring.'.$key, 'Текст результата заполнен некорректно.');
            }
        }
        foreach (['safe_steps', 'specialist_questions'] as $key) {
            if (array_key_exists($key, $scoring) && ! $this->localizedTextList($scoring[$key])) {
                $this->fail('scoring.'.$key, 'Список результата заполнен некорректно.');
            }
        }

        $comparison = $scoring['comparison'] ?? null;
        if ($comparison !== null) {
            if (! is_array($comparison)
                || ($comparison['operator'] ?? null) !== 'no_decrease'
                || ! is_array($comparison['metric_keys'] ?? null)
                || $comparison['metric_keys'] === []
                || count(array_filter($comparison['metric_keys'], 'is_string')) !== count($comparison['metric_keys'])
                || count(array_unique($comparison['metric_keys'])) !== count($comparison['metric_keys'])
                || array_diff($comparison['metric_keys'], array_keys($metrics)) !== []) {
                $this->fail('scoring.comparison', 'Показатели для сравнения выбраны некорректно.');
            }
            if (array_key_exists('basis', $comparison) && $comparison['basis'] !== 'normalized_score') {
                $this->fail('scoring.comparison', 'Основа сравнения результатов не поддерживается.');
            }
        }
    }

    /** @param array<string, array{type: string, options: mixed}> $knownQuestions */
    private function validateAnswerScale(mixed $answerScale, array $knownQuestions): void
    {
        if (! is_array($answerScale) || $answerScale === []) {
            $this->fail('scoring.answer_scale', 'Шкала ответов заполнена некорректно.');
        }
        $allowedValues = [];
        foreach ($knownQuestions as $question) {
            foreach ($this->optionValues($question['options']) as $value) {
                $allowedValues[$value] = true;
            }
        }
        foreach ($answerScale as $value => $points) {
            if ((string) $value === '' || ! is_numeric($points) || ! is_finite((float) $points)) {
                $this->fail('scoring.answer_scale', 'Шкала ответов заполнена некорректно.');
            }
            if (! isset($allowedValues[(string) $value])) {
                $this->fail('scoring.answer_scale', 'Шкала содержит недоступный вариант ответа.');
            }
        }
    }

    /** @param array<string, list<array<string, mixed>>> $thresholdsByMetric */
    private function validateThresholdRanges(array $thresholdsByMetric): void
    {
        foreach ($thresholdsByMetric as $thresholds) {
            usort($thresholds, static function (array $left, array $right): int {
                $leftMin = array_key_exists('min', $left) ? (float) $left['min'] : -INF;
                $rightMin = array_key_exists('min', $right) ? (float) $right['min'] : -INF;

                return $leftMin <=> $rightMin;
            });
            $previous = null;
            foreach ($thresholds as $threshold) {
                if ($previous !== null) {
                    $previousMax = array_key_exists('max', $previous) ? (float) $previous['max'] : INF;
                    $currentMin = array_key_exists('min', $threshold) ? (float) $threshold['min'] : -INF;
                    if ($currentMin <= $previousMax) {
                        $this->fail('scoring.thresholds', 'Диапазоны результата пересекаются.');
                    }
                    if ($this->integerRange($previous) && $this->integerRange($threshold) && $currentMin > $previousMax + 1) {
                        $this->fail('scoring.thresholds', 'Между диапазонами результата есть пропуск.');
                    }
                }
                $previous = $threshold;
            }
        }
    }

    /** @param array<string, mixed> $threshold */
    private function integerRange(array $threshold): bool
    {
        foreach (['min', 'max'] as $bound) {
            if (array_key_exists($bound, $threshold) && fmod((float) $threshold[$bound], 1.0) !== 0.0) {
                return false;
            }
        }

        return true;
    }

    private function localizedTextList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && count(array_filter($value, fn (mixed $item): bool => $this->localizedText($item))) === count($value);
    }

    /** @return list<string> */
    private function optionValues(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $values = [];
        foreach ($options as $option) {
            if (is_array($option) && is_string($option['value'] ?? null)) {
                $values[] = $option['value'];
            }
        }

        return $values;
    }

    /** @param list<string> $allowedValues */
    private function choiceValue(mixed $value, array $allowedValues): bool
    {
        return is_string($value) && in_array($value, $allowedValues, true);
    }

    /** @param list<string> $allowedValues */
    private function choiceList(mixed $value, array $allowedValues): bool
    {
        return is_array($value)
            && $value !== []
            && array_is_list($value)
            && count(array_filter($value, fn (mixed $item): bool => $this->choiceValue($item, $allowedValues))) === count($value);
    }

    /** @param list<string> $allowedValues */
    private function multipleChoiceValue(mixed $value, array $allowedValues): bool
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (is_string($item) && in_array($item, $allowedValues, true)) {
                continue;
            }
            if (! $this->choiceList($item, $allowedValues)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function canonicalConditionOperators(string $type): array
    {
        return in_array($type, self::FIELD_TYPES, true)
            ? [
                'equals' => 'Равно',
                'not_equals' => 'Не равно',
                'in' => 'Один из вариантов',
                'not_in' => 'Не один из вариантов',
                'answered' => 'Есть ответ',
                'greater_than' => 'Больше',
                'less_than' => 'Меньше',
            ]
            : [];
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
