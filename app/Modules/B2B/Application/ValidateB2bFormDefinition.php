<?php

namespace App\Modules\B2B\Application;

use Illuminate\Validation\ValidationException;

final class ValidateB2bFormDefinition
{
    /**
     * @param  array<int, mixed>  $fields
     * @return array<int, array<string, mixed>>
     */
    public function handle(array $fields): array
    {
        if (count($fields) > 30) {
            throw ValidationException::withMessages(['fields' => 'Добавьте не более 30 полей.']);
        }

        $normalized = [];
        $keys = [];
        foreach (array_values($fields) as $index => $field) {
            if (! is_array($field)) {
                throw ValidationException::withMessages(['fields' => 'Проверьте структуру полей формы.']);
            }
            $key = strtolower(trim((string) ($field['key'] ?? '')));
            $label = trim((string) ($field['label'] ?? ''));
            $type = (string) ($field['type'] ?? 'text');
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1 || in_array($key, $keys, true)) {
                throw ValidationException::withMessages(["fields.{$index}.key" => 'Используйте уникальный ключ латиницей, цифрами и _.']);
            }
            if ($label === '' || mb_strlen($label) > 160) {
                throw ValidationException::withMessages(["fields.{$index}.label" => 'Название поля обязательно и не длиннее 160 символов.']);
            }
            if (! in_array($type, ['text', 'textarea', 'select', 'checkbox'], true)) {
                throw ValidationException::withMessages(["fields.{$index}.type" => 'Выберите поддерживаемый тип поля.']);
            }
            $options = [];
            foreach (array_values(is_array($field['options'] ?? null) ? $field['options'] : []) as $optionIndex => $option) {
                if (! is_array($option)) {
                    continue;
                }
                $value = trim((string) ($option['value'] ?? ''));
                $optionLabel = trim((string) ($option['label'] ?? ''));
                if ($value === '' || preg_match('/^[a-z0-9_-]{1,40}$/', $value) !== 1 || $optionLabel === '' || mb_strlen($optionLabel) > 160) {
                    throw ValidationException::withMessages(["fields.{$index}.options.{$optionIndex}" => 'Проверьте варианты ответа.']);
                }
                if (array_key_exists($value, $options)) {
                    throw ValidationException::withMessages(["fields.{$index}.options.{$optionIndex}.value" => 'Варианты ответа должны быть уникальными.']);
                }
                $options[$value] = ['value' => $value, 'label' => $optionLabel];
            }
            if ($type === 'select' && $options === []) {
                throw ValidationException::withMessages(["fields.{$index}.options" => 'Для списка добавьте хотя бы один вариант.']);
            }
            $keys[] = $key;
            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'active' => (bool) ($field['active'] ?? true),
                'sort_order' => max(0, (int) ($field['sort_order'] ?? $index)),
                'options' => array_values($options),
            ];
        }

        usort($normalized, static fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        return $normalized;
    }
}
