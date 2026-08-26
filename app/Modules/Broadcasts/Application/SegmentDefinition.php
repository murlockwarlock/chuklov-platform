<?php

namespace App\Modules\Broadcasts\Application;

use App\Modules\Scheduling\Domain\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class SegmentDefinition
{
    /** @var array<string, list<string>> */
    private const OPERATORS = [
        'tag' => ['equals', 'in'],
        'b2b_role' => ['equals', 'in'],
        'survey_completed' => ['equals'],
        'visit_count' => ['gte'],
        'booking_status' => ['equals', 'in'],
        'last_visit' => ['before', 'after'],
        'no_future_booking' => ['equals'],
        'referral_relationship' => ['equals'],
        'attribution_source' => ['equals', 'in'],
        'language' => ['equals', 'in'],
        'verified_channel' => ['equals'],
    ];

    /** @return list<array{key: string, operator: string, value: mixed}> */
    public function validate(mixed $definition): array
    {
        if (! is_array($definition) || ! array_is_list($definition) || count($definition) > 20) {
            throw ValidationException::withMessages(['segment_definition' => 'Сегмент должен содержать не более 20 условий.']);
        }

        $validated = [];

        foreach ($definition as $filter) {
            if (! is_array($filter) || array_diff(array_keys($filter), ['key', 'operator', 'value']) !== []) {
                throw ValidationException::withMessages(['segment_definition' => 'Условие сегмента имеет неверный формат.']);
            }

            $key = is_string($filter['key'] ?? null) ? $filter['key'] : '';
            $operator = is_string($filter['operator'] ?? null) ? $filter['operator'] : '';

            if (! isset(self::OPERATORS[$key]) || ! in_array($operator, self::OPERATORS[$key], true)) {
                throw ValidationException::withMessages(['segment_definition' => 'Выбран недоступный фильтр или способ сравнения.']);
            }

            $value = $this->validatedValue($key, $operator, $filter['value'] ?? null);
            $validated[] = ['key' => $key, 'operator' => $operator, 'value' => $value];
        }

        return $validated;
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'tag' => 'Метка клиента',
            'b2b_role' => 'B2B-роль',
            'survey_completed' => 'Завершён тест',
            'visit_count' => 'Количество завершённых визитов',
            'booking_status' => 'Статус записи',
            'last_visit' => 'Дата последнего визита',
            'no_future_booking' => 'Нет будущей записи',
            'referral_relationship' => 'Пришёл по рекомендации',
            'attribution_source' => 'Источник привлечения',
            'language' => 'Язык',
            'verified_channel' => 'Подтверждённый канал',
        ];
    }

    private function validatedValue(string $key, string $operator, mixed $value): mixed
    {
        if (in_array($key, ['survey_completed', 'no_future_booking', 'referral_relationship'], true)) {
            if (! is_bool($value)) {
                throw ValidationException::withMessages(['segment_definition' => 'Логическое условие должно быть «да» или «нет».']);
            }

            return $value;
        }

        if ($key === 'visit_count') {
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                throw ValidationException::withMessages(['segment_definition' => 'Количество визитов должно быть целым числом.']);
            }

            return min((int) $value, 100000);
        }

        if ($key === 'last_visit') {
            if (! is_string($value)) {
                throw ValidationException::withMessages(['segment_definition' => 'Укажите корректную дату последнего визита.']);
            }

            try {
                return CarbonImmutable::parse($value)->startOfDay()->utc()->toIso8601String();
            } catch (\Throwable) {
                throw ValidationException::withMessages(['segment_definition' => 'Укажите корректную дату последнего визита.']);
            }
        }

        $values = $operator === 'in' ? $value : [$value];

        if (! is_array($values) || ! array_is_list($values) || $values === [] || count($values) > 50) {
            throw ValidationException::withMessages(['segment_definition' => 'Укажите допустимое значение условия.']);
        }

        $normalized = [];

        foreach ($values as $item) {
            if (! is_string($item) || trim($item) === '' || mb_strlen(trim($item)) > 120) {
                throw ValidationException::withMessages(['segment_definition' => 'Значение условия имеет неверный формат.']);
            }
            $normalized[] = trim($item);
        }

        if ($key === 'booking_status' && array_diff($normalized, array_column(BookingStatus::cases(), 'value')) !== []) {
            throw ValidationException::withMessages(['segment_definition' => 'Указан неизвестный статус записи.']);
        }
        if ($key === 'language' && array_filter($normalized, fn (string $locale): bool => preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $locale) !== 1) !== []) {
            throw ValidationException::withMessages(['segment_definition' => 'Указан неизвестный язык.']);
        }
        if ($key === 'verified_channel' && $normalized !== ['telegram']) {
            throw ValidationException::withMessages(['segment_definition' => 'Этот канал недоступен для рассылок.']);
        }

        return $operator === 'in' ? array_values(array_unique($normalized)) : $normalized[0];
    }
}
