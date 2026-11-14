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
        public ?int $complaintDefinitionId = null,
        public bool $needsConfirmation = false,
        public string $state = 'active',
        /** @var list<int> */
        public array $tagIds = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if (mb_strlen($name) > 1000) {
            throw ValidationException::withMessages(['pains' => 'Название боли не должно превышать 1000 символов.']);
        }

        $frequencyMode = is_string($data['frequency_mode'] ?? null) ? trim($data['frequency_mode']) : 'unspecified';
        $frequencyMode = $frequencyMode === '' ? 'unspecified' : $frequencyMode;
        if (! in_array($frequencyMode, ['unspecified', 'permanent', 'count'], true)) {
            throw ValidationException::withMessages(['pains' => 'Выберите корректную частоту боли.']);
        }

        $frequencyCount = self::nullableInteger($data['frequency_count'] ?? null, 'frequency_count');
        $frequencyPeriodUnit = is_string($data['frequency_period_unit'] ?? null)
            ? trim($data['frequency_period_unit'])
            : null;
        if ($frequencyMode === 'count' && ($frequencyCount === null || $frequencyPeriodUnit === null || $frequencyPeriodUnit === '')) {
            throw ValidationException::withMessages(['pains' => 'Для частоты укажите количество и период.']);
        }
        if ($frequencyMode !== 'count') {
            $frequencyCount = null;
            $frequencyPeriodUnit = null;
        }

        $timeOfDay = $data['time_of_day'] ?? ['all'];
        if (! is_array($timeOfDay)) {
            throw ValidationException::withMessages(['pains' => 'Выберите время возникновения боли.']);
        }
        $timeOfDay = array_values(array_unique(array_filter($timeOfDay, static fn (mixed $value): bool => is_string($value))));
        $allowedTime = ['all', 'morning', 'day', 'evening', 'night'];
        if ($timeOfDay === []) {
            $timeOfDay = ['all'];
        }
        if (array_diff($timeOfDay, $allowedTime) !== []) {
            throw ValidationException::withMessages(['pains' => 'Выберите корректное время возникновения боли.']);
        }
        if (in_array('all', $timeOfDay, true)) {
            $timeOfDay = ['all'];
        }

        $lineageKey = $data['lineage_key'] ?? null;
        if ($lineageKey !== null && (! is_string($lineageKey) || ! Str::isUuid($lineageKey))) {
            throw ValidationException::withMessages(['pains' => 'Не удалось определить боль для обновления.']);
        }

        $state = is_string($data['state'] ?? null) ? trim((string) $data['state']) : 'active';
        if (! in_array($state, ['active', 'resolved', 'returned'], true)) {
            throw ValidationException::withMessages(['pains' => 'Выберите корректное состояние жалобы.']);
        }

        return new self(
            name: $name,
            frequencyMode: $frequencyMode,
            frequencyCount: $frequencyCount,
            frequencyPeriodUnit: $frequencyPeriodUnit,
            timeOfDay: $timeOfDay,
            score: self::nullableScore($data['score'] ?? null, 'score'),
            beforeScore: self::nullableScore($data['before_score'] ?? null, 'before_score'),
            afterScore: self::nullableScore($data['after_score'] ?? null, 'after_score'),
            lineageKey: $lineageKey,
            complaintDefinitionId: self::nullableId($data['complaint_definition_id'] ?? null),
            needsConfirmation: filter_var($data['needs_confirmation'] ?? false, FILTER_VALIDATE_BOOLEAN),
            state: $state,
            tagIds: self::tagIds($data['tag_ids'] ?? []),
        );
    }

    /** @param array<string, mixed> $data */
    public static function isEmptyRow(array $data): bool
    {
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        $count = $data['frequency_count'] ?? null;
        $period = is_string($data['frequency_period_unit'] ?? null) ? trim($data['frequency_period_unit']) : '';
        $frequencyMode = array_key_exists('frequency_mode', $data) ? $data['frequency_mode'] : 'unspecified';
        $frequencyIsDefault = $frequencyMode === null || $frequencyMode === '' || $frequencyMode === 'unspecified';
        $time = array_key_exists('time_of_day', $data) ? $data['time_of_day'] : ['all'];
        $timeIsDefault = $time === null || $time === [] || $time === ['all'];
        $tags = array_key_exists('tag_ids', $data) ? $data['tag_ids'] : [];

        return $name === ''
            && $frequencyIsDefault
            && ($count === null || $count === '')
            && $period === ''
            && $timeIsDefault
            && ($data['score'] ?? null) === null
            && ($data['before_score'] ?? null) === null
            && ($data['after_score'] ?? null) === null
            && ($data['complaint_definition_id'] ?? null) === null
            && ! filter_var($data['needs_confirmation'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && (($data['state'] ?? 'active') === 'active')
            && ($tags === null || $tags === []);
    }

    public function withName(string $name): self
    {
        return new self(
            name: trim($name),
            frequencyMode: $this->frequencyMode,
            frequencyCount: $this->frequencyCount,
            frequencyPeriodUnit: $this->frequencyPeriodUnit,
            timeOfDay: $this->timeOfDay,
            score: $this->score,
            beforeScore: $this->beforeScore,
            afterScore: $this->afterScore,
            lineageKey: $this->lineageKey,
            complaintDefinitionId: $this->complaintDefinitionId,
            needsConfirmation: $this->needsConfirmation,
            state: $this->state,
            tagIds: $this->tagIds,
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

    private static function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $id = (int) $value;
            if ($id > 0) {
                return $id;
            }
        }

        throw ValidationException::withMessages(['pains' => 'Выберите корректную жалобу.']);
    }

    /** @return list<int> */
    private static function tagIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (! is_array($value)) {
            throw ValidationException::withMessages(['pains' => 'Выберите корректные категории проблемы.']);
        }

        $ids = [];
        foreach ($value as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw ValidationException::withMessages(['pains' => 'Выберите корректные категории проблемы.']);
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }
}
