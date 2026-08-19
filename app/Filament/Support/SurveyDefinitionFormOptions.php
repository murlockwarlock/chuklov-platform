<?php

namespace App\Filament\Support;

final class SurveyDefinitionFormOptions
{
    /**
     * @param  array<int|string, mixed>  $sections
     * @return array<string, string>
     */
    public static function allQuestionOptions(array $sections, mixed $selected = null): array
    {
        $options = [];
        foreach (self::orderedQuestions($sections) as $question) {
            $options[(string) ($question['key'] ?? '')] = self::humanText($question['label'] ?? null);
        }

        return self::withUnavailableOption($options, $selected, 'Выбранный вопрос больше недоступен.');
    }

    /**
     * @param  array<int|string, mixed>  $sections
     * @return array<string, string>
     */
    public static function previousQuestionOptions(array $sections, mixed $currentKey, mixed $selected = null): array
    {
        $options = [];
        foreach (self::orderedQuestions($sections) as $question) {
            $key = $question['key'] ?? null;
            if ($key === $currentKey) {
                break;
            }
            if (is_string($key) && $key !== '') {
                $options[$key] = self::humanText($question['label'] ?? null);
            }
        }

        return self::withUnavailableOption($options, $selected, 'Выбранный вопрос больше недоступен.');
    }

    /**
     * @param  array<int|string, mixed>  $sections
     * @return array<string, string>
     */
    public static function optionOptions(array $sections, mixed $questionKey, mixed $selected = null): array
    {
        $question = self::findQuestion($sections, $questionKey);
        $options = [];
        foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null)) {
                $options[$option['value']] = self::humanText($option['label'] ?? null);
            }
        }

        $selectedValues = is_array($selected) ? $selected : [$selected];
        foreach ($selectedValues as $value) {
            if (is_string($value) && $value !== '' && ! array_key_exists($value, $options)) {
                $options[$value] = 'Выбранный вариант больше недоступен.';
            }
        }

        return $options;
    }

    /**
     * @param  array<int|string, mixed>  $metrics
     * @return array<string, string>
     */
    public static function metricOptions(array $metrics, mixed $selected = null): array
    {
        $options = [];
        foreach ($metrics as $metric) {
            if (is_array($metric) && is_string($metric['key'] ?? null)) {
                $options[$metric['key']] = self::humanText($metric['label'] ?? null);
            }
        }

        return self::withUnavailableOption($options, $selected, 'Выбранный показатель больше недоступен.');
    }

    /** @param array<int|string, mixed> $sections */
    public static function questionType(array $sections, mixed $questionKey): ?string
    {
        return self::findQuestion($sections, $questionKey)['type'] ?? null;
    }

    /** @return array<string, string> */
    public static function conditionOperators(?string $type, mixed $selected = null): array
    {
        $operators = match ($type) {
            'single_choice' => [
                'equals' => 'Равно',
                'not_equals' => 'Не равно',
                'in' => 'Один из вариантов',
                'not_in' => 'Не один из вариантов',
                'answered' => 'Есть ответ',
            ],
            'multiple_choice' => ['answered' => 'Есть ответ'],
            'boolean' => [
                'equals' => 'Равно',
                'not_equals' => 'Не равно',
                'answered' => 'Есть ответ',
            ],
            'integer', 'number' => [
                'equals' => 'Равно',
                'not_equals' => 'Не равно',
                'greater_than' => 'Больше',
                'less_than' => 'Меньше',
                'answered' => 'Есть ответ',
            ],
            'short_text', 'long_text' => [
                'equals' => 'Равно',
                'not_equals' => 'Не равно',
                'answered' => 'Есть ответ',
            ],
            default => [],
        };

        return self::withUnavailableOption($operators, $selected, 'Сохранённое условие недоступно для редактирования.');
    }

    /** @return array<string, string> */
    public static function scoringOperators(?string $type, mixed $selected = null): array
    {
        $operators = match ($type) {
            'single_choice' => ['value_map' => 'Баллы по выбранному варианту'],
            'multiple_choice' => ['selected_sum' => 'Сумма выбранных вариантов'],
            'integer', 'number' => ['numeric_value' => 'Числовой ответ'],
            default => [],
        };

        return self::withUnavailableOption($operators, $selected, 'Сохранённое правило недоступно для редактирования.');
    }

    /** @param array<int|string, mixed> $sections */
    public static function conditionHelp(array $sections, mixed $currentKey, mixed $selected): ?string
    {
        if (! is_string($selected) || $selected === '') {
            return null;
        }
        if ($selected === $currentKey) {
            return 'Выберите вопрос выше, а не этот вопрос.';
        }
        foreach (self::orderedQuestions($sections) as $question) {
            if (($question['key'] ?? null) === $currentKey) {
                return 'Условие может ссылаться только на вопрос выше.';
            }
            if (($question['key'] ?? null) === $selected) {
                return 'Условие может ссылаться только на вопрос выше.';
            }
        }

        return 'Выбранный вопрос больше недоступен. Выберите другой вопрос или удалите условие.';
    }

    /**
     * @param  array<int|string, mixed>  $sections
     * @return array<string, mixed>
     */
    private static function findQuestion(array $sections, mixed $questionKey): array
    {
        foreach (self::orderedQuestions($sections) as $question) {
            if (($question['key'] ?? null) === $questionKey) {
                return $question;
            }
        }

        return [];
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

    /**
     * @param  array<string, string>  $options
     * @return array<string, string>
     */
    private static function withUnavailableOption(array $options, mixed $selected, string $label): array
    {
        $selectedValues = is_array($selected) ? $selected : [$selected];
        foreach ($selectedValues as $value) {
            if (is_string($value) && $value !== '' && ! array_key_exists($value, $options)) {
                $options[$value] = $label;
            }
        }

        return $options;
    }

    private static function humanText(mixed $value): string
    {
        if (is_array($value)) {
            return (string) ($value['ru'] ?? $value['en'] ?? 'Без названия');
        }

        return is_string($value) && $value !== '' ? $value : 'Без названия';
    }
}
