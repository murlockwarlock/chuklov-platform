<?php

namespace App\Filament\Support;

use App\Support\SupportedLocale;

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

        return self::withUnavailableOption($options, $selected, __('Выбранный вопрос больше недоступен.'));
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

        return self::withUnavailableOption($options, $selected, __('Выбранный вопрос больше недоступен.'));
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
                $options[$value] = __('Выбранный вариант больше недоступен.');
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

        return self::withUnavailableOption($options, $selected, __('Выбранный показатель больше недоступен.'));
    }

    /**
     * @param  array<int|string, mixed>  $sections
     * @return array<string, string>
     */
    public static function answerScaleOptions(array $sections, mixed $selected = null): array
    {
        $options = [];
        foreach (self::orderedQuestions($sections) as $question) {
            foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
                if (! is_array($option) || ! is_string($option['value'] ?? null)) {
                    continue;
                }
                $options[$option['value']] = self::humanText($option['label'] ?? null);
            }
        }

        return self::withUnavailableOption($options, $selected, __('Выбранный вариант больше недоступен.'));
    }

    /** @return array<string, string> */
    public static function normalizationOptions(mixed $selected = null): array
    {
        return self::withUnavailableOption([
            'symptom_burden_0_100' => __('Симптомная нагрузка, шкала 0–100'),
        ], $selected, __('Сохранённый способ нормализации больше недоступен.'));
    }

    /** @return array<string, string> */
    public static function comparisonOperatorOptions(mixed $selected = null): array
    {
        return self::withUnavailableOption([
            'no_decrease' => __('Не должно быть ухудшения'),
        ], $selected, __('Сохранённое сравнение больше недоступно.'));
    }

    /** @return array<string, string> */
    public static function comparisonBasisOptions(mixed $selected = null): array
    {
        return self::withUnavailableOption([
            'normalized_score' => __('Нормализованный результат'),
        ], $selected, __('Сохранённая основа сравнения больше недоступна.'));
    }

    public static function answerScaleLabel(array $sections, mixed $value): string
    {
        return self::answerScaleOptions($sections, $value)[(string) $value] ?? __('Выбранный вариант больше недоступен.');
    }

    public static function questionTypeLabel(mixed $type): string
    {
        return match ($type) {
            'single_choice' => __('Один вариант'),
            'multiple_choice' => __('Несколько вариантов'),
            'boolean' => __('Да / нет'),
            'integer' => __('Целое число'),
            'number' => __('Число'),
            'short_text' => __('Короткий текст'),
            'long_text' => __('Развёрнутый текст'),
            default => __('Тип ответа не указан'),
        };
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
                'equals' => __('Равно'),
                'not_equals' => __('Не равно'),
                'in' => __('Один из вариантов'),
                'not_in' => __('Не один из вариантов'),
                'answered' => __('Есть ответ'),
            ],
            'multiple_choice' => ['answered' => __('Есть ответ')],
            'boolean' => [
                'equals' => __('Равно'),
                'not_equals' => __('Не равно'),
                'answered' => __('Есть ответ'),
            ],
            'integer' => [
                'equals' => __('Равно'),
                'not_equals' => __('Не равно'),
                'greater_than' => __('Больше'),
                'less_than' => __('Меньше'),
                'answered' => __('Есть ответ'),
            ],
            'number' => [
                'greater_than' => __('Больше'),
                'less_than' => __('Меньше'),
                'answered' => __('Есть ответ'),
            ],
            'short_text', 'long_text' => [
                'equals' => __('Равно'),
                'not_equals' => __('Не равно'),
                'answered' => __('Есть ответ'),
            ],
            default => [],
        };

        return self::withUnavailableOption($operators, $selected, __('Сохранённое условие недоступно для редактирования.'));
    }

    /** @return array<string, string> */
    public static function scoringOperators(?string $type, mixed $selected = null): array
    {
        $operators = match ($type) {
            'single_choice' => ['value_map' => __('Баллы по выбранному варианту')],
            'multiple_choice' => ['selected_sum' => __('Сумма выбранных вариантов')],
            'integer', 'number' => ['numeric_value' => __('Числовой ответ')],
            default => [],
        };

        return self::withUnavailableOption($operators, $selected, __('Сохранённое правило недоступно для редактирования.'));
    }

    /** @param array<int|string, mixed> $sections */
    public static function conditionHelp(array $sections, mixed $currentKey, mixed $selected): ?string
    {
        if (! is_string($selected) || $selected === '') {
            return null;
        }
        if ($selected === $currentKey) {
            return __('Выберите вопрос выше, а не этот вопрос.');
        }
        $keys = array_values(array_filter(
            array_map(static fn (array $question): mixed => $question['key'] ?? null, self::orderedQuestions($sections)),
            static fn (mixed $key): bool => is_string($key) && $key !== '',
        ));
        $currentIndex = array_search($currentKey, $keys, true);
        $selectedIndex = array_search($selected, $keys, true);
        if ($selectedIndex === false || $currentIndex === false) {
            return __('Выбранный вопрос больше недоступен. Выберите другой вопрос или удалите условие.');
        }
        if ($selectedIndex < $currentIndex) {
            return null;
        }

        return __('Условие может ссылаться только на более ранний вопрос.');
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
            $locale = SupportedLocale::normalize(app()->getLocale());

            return (string) ($value[$locale] ?? $value['ru'] ?? $value['en'] ?? __('Без названия'));
        }

        return is_string($value) && $value !== '' ? $value : __('Без названия');
    }
}
