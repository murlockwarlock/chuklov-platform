<?php

namespace App\Modules\MedicalProfiles\Application;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class MedicalPainInput
{
    /** @param list<string> $timeOfDay */
    public function __construct(
        public string $name,
        public string $frequencyMode,
        public ?int $frequencyCount,
        public ?string $frequencyPeriodUnit,
        public array $timeOfDay,
        public ?int $score,
        public ?int $beforeScore,
        public ?int $afterScore,
        public ?string $lineageKey,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if ($name === '') {
            throw ValidationException::withMessages(['pains' => 'Укажите название каждой боли.']);
        }
        if (mb_strlen($name) > 1000) {
            throw ValidationException::withMessages(['pains' => 'Название боли не должно превышать 1000 символов.']);
        }

        $frequencyMode = is_string($data['frequency_mode'] ?? null) ? $data['frequency_mode'] : 'permanent';
        if (! in_array($frequencyMode, ['permanent', 'count'], true)) {
            throw ValidationException::withMessages(['pains' => 'Выберите корректную частоту боли.']);
        }

        $frequencyCount = self::nullableInteger($data['frequency_count'] ?? null, 'frequency_count');
        $frequencyPeriodUnit = is_string($data['frequency_period_unit'] ?? null)
            ? trim($data['frequency_period_unit'])
            : null;
        if ($frequencyMode === 'count' && ($frequencyCount === null || $frequencyPeriodUnit === null || $frequencyPeriodUnit === '')) {
            throw ValidationException::withMessages(['pains' => 'Для частоты укажите количество и период.']);
        }
        if ($frequencyMode === 'permanent') {
            $frequencyCount = null;
            $frequencyPeriodUnit = null;
        }

        $timeOfDay = $data['time_of_day'] ?? ['all'];
        if (! is_array($timeOfDay)) {
            throw ValidationException::withMessages(['pains' => 'Выберите время возникновения боли.']);
        }
        $timeOfDay = array_values(array_unique(array_filter($timeOfDay, static fn (mixed $value): bool => is_string($value))));
        $allowedTime = ['all', 'morning', 'day', 'evening', 'night'];
        if ($timeOfDay === [] || array_diff($timeOfDay, $allowedTime) !== []) {
            throw ValidationException::withMessages(['pains' => 'Выберите корректное время возникновения боли.']);
        }
        if (in_array('all', $timeOfDay, true)) {
            $timeOfDay = ['all'];
        }

        $lineageKey = $data['lineage_key'] ?? null;
        if ($lineageKey !== null && (! is_string($lineageKey) || ! Str::isUuid($lineageKey))) {
            throw ValidationException::withMessages(['pains' => 'Не удалось определить боль для обновления.']);
        }

        return new self(
            name: $name,
            frequencyMode: $frequencyMode,
            frequencyCount: $frequencyCount,
            frequencyPeriodUnit: $frequencyPeriodUnit === '' ? null : $frequencyPeriodUnit,
            timeOfDay: $timeOfDay,
            score: self::nullableScore($data['score'] ?? null, 'score'),
            beforeScore: self::nullableScore($data['before_score'] ?? null, 'before_score'),
            afterScore: self::nullableScore($data['after_score'] ?? null, 'after_score'),
            lineageKey: $lineageKey,
        );
    }

    private static function nullableInteger(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            $integer = $value;
        } elseif ((is_string($value) || is_float($value)) && is_numeric($value) && (float) $value === (float) (int) $value) {
            $integer = (int) $value;
        } else {
            throw ValidationException::withMessages([$field => 'Укажите целое число.']);
        }
        if ($integer < 1) {
            throw ValidationException::withMessages([$field => 'Количество должно быть больше нуля.']);
        }

        return $integer;
    }

    private static function nullableScore(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            $score = $value;
        } elseif ((is_string($value) || is_float($value)) && is_numeric($value) && (float) $value === (float) (int) $value) {
            $score = (int) $value;
        } else {
            throw ValidationException::withMessages([$field => 'Оценка должна быть целым числом от 0 до 10.']);
        }
        if ($score < 0 || $score > 10) {
            throw ValidationException::withMessages([$field => 'Оценка должна быть от 0 до 10.']);
        }

        return $score;
    }
}
