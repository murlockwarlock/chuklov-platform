<?php

namespace App\Modules\B2B\Application;

use App\Modules\B2B\Domain\Models\B2bFormConfiguration;
use Illuminate\Validation\ValidationException;

final readonly class ValidateB2bFormAnswers
{
    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function handle(B2bFormConfiguration $configuration, array $answers): array
    {
        $fields = app(ValidateB2bFormDefinition::class)->handle((array) $configuration->getAttribute('fields'));
        $allowed = [];
        foreach ($fields as $field) {
            if (($field['active'] ?? true) !== true) {
                continue;
            }
            $key = $field['key'];
            $value = $answers[$key] ?? (($field['type'] ?? 'text') === 'checkbox' ? false : null);
            if (($field['required'] ?? false) && (($field['type'] ?? '') === 'checkbox' ? $value !== true : trim((string) $value) === '')) {
                throw ValidationException::withMessages(["form_answers.{$key}" => 'Заполните это поле.']);
            }
            if (($field['type'] ?? '') === 'select' && $value !== null && ! in_array($value, array_column($field['options'] ?? [], 'value'), true)) {
                throw ValidationException::withMessages(["form_answers.{$key}" => 'Выберите значение из списка.']);
            }
            if (($field['type'] ?? '') === 'checkbox') {
                if (! is_bool($value)) {
                    throw ValidationException::withMessages(["form_answers.{$key}" => 'Укажите корректное значение.']);
                }
                $allowed[$key] = $value;

                continue;
            }
            $normalized = $value === null ? null : trim((string) $value);
            if (is_string($normalized) && mb_strlen($normalized) > 5000) {
                throw ValidationException::withMessages(["form_answers.{$key}" => 'Ответ слишком длинный.']);
            }
            $allowed[$key] = $normalized;
        }

        return $allowed;
    }
}
