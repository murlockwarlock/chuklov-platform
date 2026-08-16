<?php

namespace App\Modules\Surveys\Domain\Services;

use Illuminate\Validation\ValidationException;

final class SurveyScorer
{
    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scoring
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function complete(array $definition, array $scoring, array $answers): array
    {
        $visibleQuestions = $this->visibleQuestions($definition, $answers);
        $validatedAnswers = $this->validatedAnswers($visibleQuestions, $answers, true);

        $metrics = [];
        foreach ($scoring['metrics'] ?? [] as $metric) {
            $metrics[$metric['key']] = ['label' => $metric['label'], 'value' => 0.0];
        }
        foreach ($scoring['rules'] ?? [] as $rule) {
            if (! array_key_exists($rule['question_key'], $validatedAnswers)) {
                continue;
            }
            $metrics[$rule['metric_key']]['value'] += $this->points($rule, $validatedAnswers[$rule['question_key']]);
        }

        $thresholds = [];
        $tags = [];
        foreach ($scoring['thresholds'] ?? [] as $threshold) {
            $value = $metrics[$threshold['metric_key']]['value'] ?? null;
            if ($value === null || ! $this->within($value, $threshold)) {
                continue;
            }
            $thresholds[] = [
                'metric_key' => $threshold['metric_key'],
                'tag' => $threshold['tag'],
                'label' => $threshold['label'] ?? $threshold['tag'],
            ];
            $tags[] = $threshold['tag'];
        }

        return [
            'metrics' => $metrics,
            'thresholds' => $thresholds,
            'tags' => array_values(array_unique($tags)),
            'visible_question_keys' => array_column($visibleQuestions, 'key'),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function validateDraft(array $definition, array $answers): array
    {
        return $this->validatedAnswers($this->visibleQuestions($definition, $answers), $answers, false);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    public function visibleQuestions(array $definition, array $answers): array
    {
        $questions = [];
        foreach ($definition['sections'] ?? [] as $section) {
            foreach ($section['questions'] ?? [] as $question) {
                if ($this->conditionMatches($question['condition'] ?? null, $answers)) {
                    $questions[] = $question;
                }
            }
        }

        return $questions;
    }

    /** @param array<string, mixed> $answers */
    private function conditionMatches(mixed $condition, array $answers): bool
    {
        if ($condition === null) {
            return true;
        }
        if (! is_array($condition)) {
            return false;
        }
        $actual = $answers[$condition['question_key'] ?? ''] ?? null;
        $expected = $condition['value'] ?? null;

        return match ($condition['operator'] ?? null) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'not_in' => is_array($expected) && ! in_array($actual, $expected, true),
            'answered' => ! $this->emptyAnswer($actual),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            default => false,
        };
    }

    /** @param array<string, mixed> $rule */
    private function points(array $rule, mixed $answer): float
    {
        return match ($rule['operator']) {
            'value_map' => (float) ($rule['points'][(string) $answer] ?? ($rule['default'] ?? 0)),
            'selected_sum' => is_array($answer) ? array_sum(array_map(fn ($value): float => (float) ($rule['points'][(string) $value] ?? 0), $answer)) : 0.0,
            'numeric_value' => is_numeric($answer) ? (float) $answer * (float) ($rule['multiplier'] ?? 1) : 0.0,
            default => 0.0,
        };
    }

    /** @param array<string, mixed> $threshold */
    private function within(float $value, array $threshold): bool
    {
        return (! isset($threshold['min']) || $value >= (float) $threshold['min'])
            && (! isset($threshold['max']) || $value <= (float) $threshold['max']);
    }

    /** @param array<string, mixed> $question */
    private function assertAnswerType(array $question, mixed $value): void
    {
        $valid = match ($question['type']) {
            'single_choice', 'short_text', 'long_text' => is_string($value),
            'multiple_choice' => is_array($value) && array_is_list($value) && count(array_filter($value, 'is_string')) === count($value),
            'boolean' => is_bool($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['answers.'.$question['key'] => 'Формат ответа не поддерживается.']);
        }
        if (in_array($question['type'], ['single_choice', 'multiple_choice'], true)) {
            $allowed = array_column($question['options'] ?? [], 'value');
            $submitted = is_array($value) ? $value : [$value];
            if (array_diff($submitted, $allowed) !== []) {
                throw ValidationException::withMessages(['answers.'.$question['key'] => 'Выбран недоступный вариант ответа.']);
            }
        }
        if ($question['type'] === 'short_text' && is_string($value) && mb_strlen($value) > 500) {
            throw ValidationException::withMessages(['answers.'.$question['key'] => 'Короткий ответ не должен превышать 500 символов.']);
        }
        if ($question['type'] === 'long_text' && is_string($value) && mb_strlen($value) > 10000) {
            throw ValidationException::withMessages(['answers.'.$question['key'] => 'Ответ не должен превышать 10 000 символов.']);
        }
        if ($question['type'] === 'multiple_choice' && is_array($value) && count($value) > 200) {
            throw ValidationException::withMessages(['answers.'.$question['key'] => 'Выбрано слишком много вариантов ответа.']);
        }
        if ($question['type'] === 'number' && ! is_finite((float) $value)) {
            throw ValidationException::withMessages(['answers.'.$question['key'] => 'Укажите конечное числовое значение.']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function validatedAnswers(array $questions, array $answers, bool $requireCompleted): array
    {
        $validated = [];
        foreach ($questions as $question) {
            $key = $question['key'];
            $value = $answers[$key] ?? null;
            if ($requireCompleted && ($question['required'] ?? false) === true && $this->emptyAnswer($value)) {
                throw ValidationException::withMessages(["answers.{$key}" => 'Ответьте на обязательный вопрос.']);
            }
            if (! $this->emptyAnswer($value)) {
                $this->assertAnswerType($question, $value);
                $validated[$key] = $value;
            }
        }

        return $validated;
    }

    private function emptyAnswer(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
