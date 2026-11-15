<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Sessions\Application\DTOs\MedicalClinicalEvaluation;
use App\Modules\Sessions\Domain\Enums\MedicalTestType;
use Illuminate\Validation\ValidationException;

final class MedicalClinicalTypeEngine
{
    public function supports(MedicalTestType $type): bool
    {
        return match ($type) {
            MedicalTestType::T1,
            MedicalTestType::T2,
            MedicalTestType::T3,
            MedicalTestType::T4,
            MedicalTestType::T5,
            MedicalTestType::T6,
            MedicalTestType::T7,
            MedicalTestType::T8,
            MedicalTestType::T9,
            MedicalTestType::T10,
            MedicalTestType::T11,
            MedicalTestType::T12 => true,
        };
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>|null $payload
     */
    public function validate(
        MedicalTestType $type,
        array $configuration,
        ?array $before,
        ?array $after,
        ?array $payload = null,
    ): void {
        $this->validatePhase($type, $configuration, $before, 'before');
        $this->validatePhase($type, $configuration, $after, 'after');

        if ($payload !== null) {
            $this->validatePayloadMetadata($configuration, $payload);
        }

        if ($type === MedicalTestType::T4) {
            $this->validateFormula($configuration, $before, 'before');
            $this->validateFormula($configuration, $after, 'after');
        }
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function evaluate(
        MedicalTestType $type,
        array $configuration,
        ?array $before,
        ?array $after,
    ): MedicalClinicalEvaluation {
        return match ($type) {
            MedicalTestType::T1 => $this->tone($configuration, $before, $after),
            MedicalTestType::T2 => $this->methodComputer($configuration, $before, $after),
            MedicalTestType::T3 => $this->palpation($configuration, $before, $after),
            MedicalTestType::T4 => $this->formula($configuration, $before, $after),
            MedicalTestType::T5 => $this->yesNo($configuration, $before, $after),
            MedicalTestType::T6 => $this->painPoint($configuration, $before, $after),
            MedicalTestType::T7 => $this->muscle($configuration, $before, $after),
            MedicalTestType::T8 => $this->traction($configuration, $before, $after),
            MedicalTestType::T9 => $this->mobility($configuration, $before, $after),
            MedicalTestType::T10 => $this->visualMap($configuration, $before, $after),
            MedicalTestType::T11 => $this->findings($configuration, $before, $after),
            MedicalTestType::T12 => $this->energy($configuration, $before, $after),
        };
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function tone(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        return $this->rowIndexEvaluation($configuration, $before, $after, allowTone: true);
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function methodComputer(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        $rules = is_array($configuration['rules'] ?? null) ? $configuration['rules'] : [];
        $violationIndexes = is_array($rules['index_violation'] ?? null) ? $rules['index_violation'] : [1, 2, 3];
        return $this->evaluationFromRows(
            $this->methodRows($before),
            $this->methodRows($after),
            fn (array $row): bool => in_array((int) ($row['index'] ?? 0), array_map('intval', $violationIndexes), true)
                || ($row['marked'] ?? false) === true
                || in_array(mb_strtolower(trim((string) ($row['answer'] ?? '')), 'UTF-8'), ['yes', 'да', 'true', '1'], true)
                || $this->hasFinding($row),
        );
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function palpation(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        $before = $this->findingRows($before);
        $after = $this->findingRows($after);

        return $this->evaluationFromRows($before, $after, static function (array $row): bool {
            $finding = mb_strtolower(trim((string) ($row['finding'] ?? '')), 'UTF-8');

            return ($finding !== '' && ! in_array($finding, ['normal', 'none', 'нет', 'норма'], true))
                || ($row['marked'] ?? false) === true
                || (int) ($row['index'] ?? 0) > 0;
        });
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function formula(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        $rules = is_array($configuration['rules'] ?? null) ? $configuration['rules'] : [];
        $allowed = [];
        foreach (is_array($rules['allowed_sequences'] ?? null) ? $rules['allowed_sequences'] : [] as $sequence) {
            if (! is_array($sequence)) {
                continue;
            }
            $allowed[] = array_values(array_map(
                static fn (mixed $value): string => strtoupper(trim((string) $value)),
                $sequence,
            ));
        }
        $countTransitions = (bool) ($rules['broken_transition_count'] ?? false);
        $hasSequence = $this->hasSequence($before) || $this->hasSequence($after);
        if ($hasSequence && ($allowed === [] || ! array_key_exists('broken_transition_count', $rules))) {
            return new MedicalClinicalEvaluation(
                causeIds: array_values(array_unique([...$this->causeIds($before), ...$this->causeIds($after)])),
                warnings: ['Для формулы не настроено обязательное правило подсчёта; подсчёт отключён.'],
                failClosed: true,
            );
        }
        $beforeResult = $this->formulaPhase($before, $allowed, $countTransitions);
        $afterResult = $this->formulaPhase($after, $allowed, $countTransitions);

        return new MedicalClinicalEvaluation(
            beforeCount: count($beforeResult['keys']),
            afterCount: count($afterResult['keys']),
            beforeViolationKeys: $beforeResult['keys'],
            afterViolationKeys: $afterResult['keys'],
            causeIds: array_values(array_unique([...$this->causeIds($before), ...$this->causeIds($after)])),
        );
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function yesNo(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        return $this->evaluationFromRows(
            $this->findingRows($before),
            $this->findingRows($after),
            fn (array $row): bool => ($row['marked'] ?? false) === true
                || in_array(mb_strtolower(trim((string) ($row['answer'] ?? '')), 'UTF-8'), ['yes', 'да', 'true', '1'], true),
        );
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function painPoint(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        $base = $this->rowIndexEvaluation($configuration, $before, $after);
        $causeIds = array_values(array_unique([...$base->causeIds, ...$this->causeIds($before), ...$this->causeIds($after)]));

        return new MedicalClinicalEvaluation(
            beforeCount: $base->beforeCount,
            afterCount: $base->afterCount,
            beforeViolationKeys: $base->beforeViolationKeys,
            afterViolationKeys: $base->afterViolationKeys,
            causeIds: $causeIds,
        );
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function muscle(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        return $this->rowIndexEvaluation($configuration, $before, $after);
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function traction(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        $rules = is_array($configuration['rules'] ?? null) ? $configuration['rules'] : [];
        $base = $this->rowIndexEvaluation($configuration, $before, $after, allowTone: true);
        $warning = false;
        foreach ([$before, $after] as $phase) {
            foreach ($this->findingRows($phase) as $row) {
                if (in_array(mb_strtolower(trim((string) ($row['tone'] ?? '')), 'UTF-8'), ['hypotone', 'гипотонус'], true)) {
                    $warning = true;
                    break 2;
                }
            }
        }

        return new MedicalClinicalEvaluation(
            beforeCount: $base->beforeCount,
            afterCount: $base->afterCount,
            beforeViolationKeys: $base->beforeViolationKeys,
            afterViolationKeys: $base->afterViolationKeys,
            causeIds: $base->causeIds,
            warnings: $warning && (bool) ($rules['warning_on_hypotone'] ?? true)
                ? ['вытяжение в этом отделе не делать']
                : [],
        );
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function mobility(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        return $this->rowIndexEvaluation($configuration, $before, $after);
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function visualMap(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        $rules = is_array($configuration['rules'] ?? null) ? $configuration['rules'] : [];
        $violationIndexes = is_array($rules['index_violation'] ?? null) ? $rules['index_violation'] : [1, 2, 3];
        $rowsBefore = $this->findingRows($before);
        $rowsAfter = $this->findingRows($after);
        $isViolation = static function (array $row) use ($violationIndexes): bool {
            if (($row['index'] ?? null) !== null) {
                return in_array((int) $row['index'], array_map('intval', $violationIndexes), true);
            }

            return ($row['marked'] ?? false) === true
                || in_array(mb_strtolower(trim((string) ($row['deviation'] ?? '')), 'UTF-8'), ['yes', 'да', 'true', '1', 'deviation', 'abnormal'], true);
        };

        return $this->evaluationFromRows($rowsBefore, $rowsAfter, $isViolation);
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function findings(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        $beforeRows = [...$this->findingRows($before), ...$this->nestedRows($before, 'feelings')];
        $afterRows = [...$this->findingRows($after), ...$this->nestedRows($after, 'feelings')];

        return $this->evaluationFromRows(
            $beforeRows,
            $afterRows,
            fn (array $row): bool => ($row['marked'] ?? false) === true
                || in_array(mb_strtolower(trim((string) ($row['answer'] ?? '')), 'UTF-8'), ['yes', 'да', 'true', '1'], true)
                || $this->hasFinding($row),
        );
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function energy(array $configuration, ?array $before, ?array $after): MedicalClinicalEvaluation
    {
        $rules = is_array($configuration['rules'] ?? null) ? $configuration['rules'] : [];
        $threshold = $rules['energy_threshold'] ?? null;
        $normalTor = $rules['normal_tor_direction'] ?? null;
        $chakraViolation = (bool) ($rules['chakra_violation'] ?? false);
        $needsThreshold = $this->phaseContainsKey($before, 'energy') || $this->phaseContainsKey($after, 'energy');
        $needsTor = $this->phaseContainsKey($before, 'tor_direction') || $this->phaseContainsKey($after, 'tor_direction');
        $failClosed = ($needsThreshold && ! is_numeric($threshold)) || ($needsTor && ! is_string($normalTor));
        $warnings = [];
        if ($failClosed) {
            $warnings[] = 'Для этого теста не настроена обязательная клиническая норма; подсчёт отключён.';
        }

        $beforeResult = $this->energyPhase($before, $threshold, $normalTor, $chakraViolation);
        $afterResult = $this->energyPhase($after, $threshold, $normalTor, $chakraViolation);

        return new MedicalClinicalEvaluation(
            beforeCount: count($beforeResult),
            afterCount: count($afterResult),
            beforeViolationKeys: $beforeResult,
            afterViolationKeys: $afterResult,
            causeIds: array_values(array_unique([...$this->causeIds($before), ...$this->causeIds($after)])),
            warnings: $warnings,
            failClosed: $failClosed,
        );
    }

    /**
     * @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function rowIndexEvaluation(array $configuration, ?array $before, ?array $after, bool $allowTone = false): MedicalClinicalEvaluation
    {
        $rules = is_array($configuration['rules'] ?? null) ? $configuration['rules'] : [];
        $violationIndexes = is_array($rules['index_violation'] ?? null) ? $rules['index_violation'] : [1, 2, 3];
        $toneValues = is_array($rules['tone_violation'] ?? null) ? $rules['tone_violation'] : ['hypotone', 'hypertone', 'гипотонус', 'гипертонус'];
        $isViolation = static function (array $row) use ($violationIndexes, $toneValues, $allowTone): bool {
            $index = $row['index'] ?? null;
            if ($index !== null) {
                return in_array((int) $index, array_map('intval', $violationIndexes), true);
            }
            if ($allowTone && in_array(mb_strtolower(trim((string) ($row['tone'] ?? '')), 'UTF-8'), $toneValues, true)) {
                return true;
            }

            return ($row['marked'] ?? false) === true;
        };

        return $this->evaluationFromRows($this->findingRows($before), $this->findingRows($after), $isViolation);
    }

    /**
     * @param list<array<string, mixed>> $beforeRows
     * @param list<array<string, mixed>> $afterRows
     * @param callable(array<string, mixed>): bool $isViolation
     */
    private function evaluationFromRows(array $beforeRows, array $afterRows, callable $isViolation): MedicalClinicalEvaluation
    {
        $beforeKeys = [];
        $afterKeys = [];
        $causeIds = [];
        foreach ($beforeRows as $row) {
            $causeIds = [...$causeIds, ...$this->causeIds($row)];
            if ($isViolation($row)) {
                $beforeKeys[$this->rowKey($row)] = true;
            }
        }
        foreach ($afterRows as $row) {
            $causeIds = [...$causeIds, ...$this->causeIds($row)];
            if ($isViolation($row)) {
                $afterKeys[$this->rowKey($row)] = true;
            }
        }

        return new MedicalClinicalEvaluation(
            beforeCount: count($beforeKeys),
            afterCount: count($afterKeys),
            beforeViolationKeys: array_keys($beforeKeys),
            afterViolationKeys: array_keys($afterKeys),
            causeIds: array_values(array_unique(array_filter(array_map('intval', $causeIds), static fn (int $id): bool => $id > 0))),
        );
    }

    /** @param array<string, mixed>|null $phase
     * @return list<array<string, mixed>>
     */
    private function findingRows(?array $phase): array
    {
        if ($phase === null) {
            return [];
        }
        $rows = is_array($phase['rows'] ?? null) ? array_values(array_filter($phase['rows'], 'is_array')) : [];
        foreach (is_array($phase['subtests'] ?? null) ? $phase['subtests'] : [] as $subtest) {
            if (! is_array($subtest) || ($subtest['enabled'] ?? true) === false) {
                continue;
            }
            if (is_array($subtest['rows'] ?? null)) {
                $rows = [...$rows, ...array_values(array_filter($subtest['rows'], 'is_array'))];
            }
            if (is_array($subtest['provoking_points'] ?? null)) {
                $rows = [...$rows, ...array_values(array_filter($subtest['provoking_points'], 'is_array'))];
            }
        }

        return $rows;
    }

    /** @param array<string, mixed>|null $phase
     * @return list<array<string, mixed>>
     */
    private function methodRows(?array $phase): array
    {
        if ($phase === null) {
            return [];
        }

        $rows = $this->findingRows($phase);
        if (array_key_exists('index', $phase)) {
            $rows[] = [
                'key' => 'method',
                'index' => $phase['index'],
                'marked' => $phase['marked'] ?? false,
                'cause_ids' => $phase['cause_ids'] ?? [],
                'primary_cause_id' => $phase['primary_cause_id'] ?? null,
            ];
        }
        foreach (is_array($phase['subtests'] ?? null) ? $phase['subtests'] : [] as $subtest) {
            if (! is_array($subtest) || ($subtest['enabled'] ?? true) === false) {
                continue;
            }
            if (($subtest['type'] ?? null) === MedicalTestType::T2->value && array_key_exists('index', $subtest)) {
                $rows[] = [
                    'key' => 'method:'.trim((string) ($subtest['key'] ?? count($rows))),
                    'index' => $subtest['index'],
                    'marked' => $subtest['marked'] ?? false,
                    'cause_ids' => $subtest['cause_ids'] ?? [],
                    'primary_cause_id' => $subtest['primary_cause_id'] ?? null,
                ];
            }
            if (($subtest['type'] ?? null) === MedicalTestType::T5->value) {
                foreach (is_array($subtest['rows'] ?? null) ? $subtest['rows'] : [] as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }
            }
        }

        return $rows;
    }

    /** @param array<string, mixed>|null $phase
     * @return list<array<string, mixed>>
     */
    private function nestedRows(?array $phase, string $key): array
    {
        $rows = is_array($phase[$key] ?? null) ? array_values(array_filter($phase[$key], 'is_array')) : [];
        foreach (is_array($phase['subtests'] ?? null) ? $phase['subtests'] : [] as $subtest) {
            if (! is_array($subtest) || ($subtest['enabled'] ?? true) === false) {
                continue;
            }
            if (is_array($subtest[$key] ?? null)) {
                $rows = [...$rows, ...array_values(array_filter($subtest[$key], 'is_array'))];
            }
        }

        return $rows;
    }

    /** @param array<string, mixed>|null $phase
     * @return list<int>
     */
    private function causeIds(?array $phase): array
    {
        if ($phase === null) {
            return [];
        }

        $ids = [];
        $walk = function (mixed $value, ?string $key = null) use (&$walk, &$ids): void {
            if ($key !== null && in_array($key, ['cause_ids', 'primary_cause_id'], true)) {
                foreach (is_array($value) ? $value : [$value] as $id) {
                    if (is_numeric($id) && (int) $id > 0) {
                        $ids[] = (int) $id;
                    }
                }

                return;
            }
            if (is_array($value)) {
                foreach ($value as $childKey => $child) {
                    $walk($child, is_string($childKey) ? $childKey : null);
                }
            }
        };
        $walk($phase);

        return array_values(array_unique($ids));
    }

    /** @param array<string, mixed> $row */
    private function rowKey(array $row): string
    {
        $key = trim((string) ($row['dedupe_key'] ?? $row['key'] ?? 'row'));
        $side = trim((string) ($row['side'] ?? ''));

        return $key.($side === '' ? '' : ':'.$side);
    }

    /** @param array<string, mixed>|null $phase
     * @param list<array<int, string>> $allowed
     * @return array{keys:list<string>}
     */
    private function formulaPhase(?array $phase, array $allowed, bool $countTransitions): array
    {
        $sequence = is_array($phase['sequence'] ?? null) ? array_values($phase['sequence']) : [];
        $sequence = array_map(static fn (mixed $value): mixed => is_array($value) ? ($value['value'] ?? null) : $value, $sequence);
        if (count($sequence) < 2) {
            return ['keys' => []];
        }
        $normalized = array_map(static fn (mixed $value): string => strtoupper(trim((string) $value)), $sequence);
        if (in_array('', $normalized, true)) {
            return ['keys' => []];
        }
        if (in_array($normalized, $allowed, true)) {
            return ['keys' => []];
        }
        if (! $countTransitions) {
            return ['keys' => ['formula']];
        }

        $keys = [];
        for ($index = 1; $index < count($normalized); $index++) {
            if ($normalized[$index] === $normalized[$index - 1]) {
                $keys[] = 'transition:'.$index;
            }
        }

        return ['keys' => $keys === [] ? ['formula'] : $keys];
    }

    /**
     * @param array<string, mixed>|null $phase
     * @return list<string>
     */
    private function energyPhase(?array $phase, mixed $threshold, mixed $normalTor, bool $chakraViolation): array
    {
        if ($phase === null) {
            return [];
        }
        $keys = [];
        $sources = [$phase];
        foreach (is_array($phase['subtests'] ?? null) ? $phase['subtests'] : [] as $subtest) {
            if (is_array($subtest) && ($subtest['enabled'] ?? true) !== false) {
                $sources[] = $subtest;
            }
        }
        foreach ($sources as $sourceIndex => $source) {
            $sourceKey = $sourceIndex === 0
                ? ''
                : trim((string) ($source['key'] ?? $sourceIndex));
            if (is_numeric($threshold) && is_numeric($source['energy'] ?? null) && (float) $source['energy'] < (float) $threshold) {
                $keys[] = $sourceKey === '' ? 'energy' : 'energy:'.$sourceKey;
            }
            if ($chakraViolation) {
                $chakras = [
                    ...(is_array($source['chakras'] ?? null) ? $source['chakras'] : []),
                    ...(is_array($source['rows'] ?? null) ? $source['rows'] : []),
                ];
                foreach ($chakras as $chakra) {
                    if (is_array($chakra) && ($chakra['marked'] ?? false) === true) {
                        $keys[] = 'chakra:'.trim((string) ($chakra['key'] ?? count($keys)));
                    }
                }
            }
            if (is_string($normalTor) && is_string($source['tor_direction'] ?? null) && $source['tor_direction'] !== $normalTor) {
                $keys[] = $sourceKey === '' ? 'tor_direction' : 'tor_direction:'.$sourceKey;
            }
        }

        return array_values(array_unique($keys));
    }

    /** @param array<string, mixed>|null $phase */
    private function phaseContainsKey(?array $phase, string $key): bool
    {
        if ($phase === null) {
            return false;
        }
        if (array_key_exists($key, $phase) && $phase[$key] !== null && $phase[$key] !== '') {
            return true;
        }

        foreach (is_array($phase['subtests'] ?? null) ? $phase['subtests'] : [] as $subtest) {
            if (is_array($subtest) && ($subtest['enabled'] ?? true) !== false && array_key_exists($key, $subtest) && $subtest[$key] !== null && $subtest[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed>|null $phase */
    private function hasSequence(?array $phase): bool
    {
        if ($phase === null || ! is_array($phase['sequence'] ?? null)) {
            return false;
        }

        foreach ($phase['sequence'] as $item) {
            $value = is_array($item) ? ($item['value'] ?? null) : $item;
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $phase
     */
    private function validateFormula(array $configuration, ?array $phase, string $name): void
    {
        if ($phase === null || ! $this->hasSequence($phase)) {
            return;
        }
        $sequence = array_values($phase['sequence']);
        if (count($sequence) !== 4) {
            $this->invalid($name.'.sequence', 'Формула должна содержать четыре зоны.');
        }
        $choices = [];
        foreach (is_array($configuration['rows'] ?? null) ? $configuration['rows'] : [] as $row) {
            if (is_array($row) && is_array($row['choices'] ?? null)) {
                $choices = [...$choices, ...$this->optionValues($row['choices'])];
            }
        }
        foreach ($sequence as $index => $item) {
            $value = is_array($item) ? ($item['value'] ?? null) : $item;
            if (! is_string($value) || trim($value) === '') {
                $this->invalid($name.'.sequence.'.$index, 'Выберите сторону для каждой зоны формулы.');
            }
            if ($choices !== [] && ! in_array((string) $value, $choices, true)) {
                $this->invalid($name.'.sequence.'.$index, 'Выбранный вариант формулы отсутствует в настройке каталога.');
            }
        }
    }

    /** @param array<string, mixed> $configuration
     * @param array<string, mixed>|null $phase
     */
    private function validatePhase(MedicalTestType $type, array $configuration, ?array $phase, string $name): void
    {
        if ($phase === null) {
            return;
        }

        $this->validatePhaseScalarFields($configuration, $phase, $name);
        $this->validateIndex($phase['index'] ?? null, $configuration['index_enabled'] ?? true, $name.'.index');
        $this->validateRows($type, $configuration, $phase['rows'] ?? [], $configuration['rows'] ?? [], $name.'.rows');
        $this->validateRows($type, $configuration, $phase['feelings'] ?? [], $configuration['feelings'] ?? [], $name.'.feelings');

        $configuredSubtests = [];
        foreach (is_array($configuration['subtests'] ?? null) ? $configuration['subtests'] : [] as $subtest) {
            if (is_array($subtest) && filled($subtest['key'] ?? null)) {
                $configuredSubtests[(string) $subtest['key']] = $subtest;
            }
        }
        foreach (is_array($phase['subtests'] ?? null) ? $phase['subtests'] : [] as $index => $subtest) {
            if (! is_array($subtest)) {
                $this->invalid($name.'.subtests.'.$index, 'Подтест заполнен неверно.');
            }
            $key = trim((string) ($subtest['key'] ?? ''));
            $configured = $configuredSubtests[$key] ?? null;
            if ($key === '' || ! is_array($configured)) {
                $this->invalid($name.'.subtests.'.$index, 'Выбранный подтест отсутствует в каталоге.');
            }
            if (($subtest['enabled'] ?? true) === false) {
                continue;
            }
            $subtestType = is_string($configured['type'] ?? null)
                ? (MedicalTestType::tryFrom((string) $configured['type']) ?? $type)
                : $type;
            $subtestConfiguration = array_replace($configuration, $configured);
            $this->validatePhaseScalarFields($subtestConfiguration, $subtest, $name.'.subtests.'.$key);
            $this->validateIndex(
                $subtest['index'] ?? null,
                $subtestConfiguration['index_enabled'] ?? true,
                $name.'.subtests.'.$key.'.index',
            );
            $this->validateRows($subtestType, $subtestConfiguration, $subtest['rows'] ?? [], $configured['rows'] ?? [], $name.'.subtests.'.$key.'.rows');
            $this->validateRows($subtestType, $subtestConfiguration, $subtest['provoking_points'] ?? [], $configured['provoking_points'] ?? [], $name.'.subtests.'.$key.'.provoking_points');
            $this->validateRows($subtestType, $subtestConfiguration, $subtest['feelings'] ?? [], $configured['feelings'] ?? [], $name.'.subtests.'.$key.'.feelings');
        }
    }

    /** @param array<string, mixed> $configuration
     * @param array<string, mixed> $phase
     */
    private function validatePhaseScalarFields(array $configuration, array $phase, string $name): void
    {
        $this->validateNumber($phase['pain_intensity'] ?? null, 0, 10, $name.'.pain_intensity');
        $this->validateNumber($phase['tl_reduction_percent'] ?? null, -100, 100, $name.'.tl_reduction_percent');
        $this->validateNumber($phase['energy'] ?? null, 0, 1000, $name.'.energy');
        $torDirection = $phase['tor_direction'] ?? null;
        if ($torDirection !== null && $torDirection !== '') {
            if (! in_array((string) $torDirection, ['R', 'L'], true)) {
                $this->invalid($name.'.tor_direction', 'Направление тор-поля должно быть R или L.');
            }
        }
        $method = $phase['method'] ?? null;
        if ($method !== null && $method !== '') {
            $options = $this->optionValues($configuration['method_options'] ?? []);
            if ($options !== [] && ! in_array((string) $method, $options, true)) {
                $this->invalid($name.'.method', 'Выбранный метод отсутствует в настройке каталога.');
            }
        }
        $movement = $phase['movement'] ?? null;
        if ($movement !== null && $movement !== '') {
            $options = $this->optionValues($configuration['movement_options'] ?? []);
            if ($options !== [] && ! in_array((string) $movement, $options, true)) {
                $this->invalid($name.'.movement', 'Выбранное движение отсутствует в настройке каталога.');
            }
        }
        $priorityChain = $phase['priority_chain'] ?? null;
        if ($priorityChain !== null && $priorityChain !== '') {
            $options = $this->optionValues($configuration['priority_options'] ?? []);
            if ($options !== [] && ! in_array((string) $priorityChain, $options, true)) {
                $this->invalid($name.'.priority_chain', 'Выбранная цепь отсутствует в настройке каталога.');
            }
        }
        $violationType = $phase['violation_type'] ?? null;
        if ($violationType !== null && $violationType !== '') {
            $options = $this->optionValues($configuration['violation_types'] ?? []);
            if ($options !== [] && ! in_array((string) $violationType, $options, true)) {
                $this->invalid($name.'.violation_type', 'Выбранный тип нарушения отсутствует в настройке каталога.');
            }
        }
    }

    /** @param array<string, mixed> $configuration
     * @param array<string, mixed> $payload
     */
    private function validatePayloadMetadata(array $configuration, array $payload): void
    {
        $value = $payload['violation_type'] ?? null;
        if ($value === null || $value === '') {
            return;
        }

        $options = $this->optionValues($configuration['violation_types'] ?? []);
        if ($options !== [] && ! in_array((string) $value, $options, true)) {
            $this->invalid('violation_type', 'Выбранный тип нарушения отсутствует в настройке каталога.');
        }
    }

    /** @param array<string, mixed> $configuration
     * @param mixed $rows
     * @param mixed $configuredRows
     */
    private function validateRows(MedicalTestType $type, array $configuration, mixed $rows, mixed $configuredRows, string $name): void
    {
        if ($rows === null || $rows === []) {
            return;
        }
        if (! is_array($rows) || ! is_array($configuredRows)) {
            $this->invalid($name, 'Строки теста заполнены неверно.');
        }
        $definitions = [];
        foreach ($configuredRows as $configuredRow) {
            if (is_array($configuredRow) && filled($configuredRow['key'] ?? null)) {
                $definitions[(string) $configuredRow['key']] = $configuredRow;
            }
        }
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $this->invalid($name.'.'.$index, 'Строка теста заполнена неверно.');
            }
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '' || ! isset($definitions[$key])) {
                $this->invalid($name.'.'.$index, 'Строка отсутствует в настройке каталога.');
            }
            $rowConfiguration = array_replace($configuration, $definitions[$key]);
            $this->validateRow($type, $rowConfiguration, $row, $name.'.'.$index);
        }
    }

    /** @param array<string, mixed> $configuration
     * @param array<string, mixed> $row
     */
    private function validateRow(MedicalTestType $type, array $configuration, array $row, string $name): void
    {
        if (array_key_exists('input_mode', $row) && $row['input_mode'] !== null && $row['input_mode'] !== ($configuration['input_mode'] ?? $row['input_mode'])) {
            $this->invalid($name.'.input_mode', 'Способ ответа строки изменён вне настройки каталога.');
        }
        if (array_key_exists('side', $configuration) && ($row['side'] ?? null) !== ($configuration['side'] ?? null)) {
            $this->invalid($name.'.side', 'Сторона строки изменена вне настройки каталога.');
        }
        $indexEnabled = array_key_exists('index_enabled', $configuration)
            ? (bool) $configuration['index_enabled']
            : ! in_array($type, [MedicalTestType::T5, MedicalTestType::T11, MedicalTestType::T12], true);
        $this->validateIndex($row['index'] ?? null, $indexEnabled, $name.'.index');
        $angle = $row['angle'] ?? null;
        $this->validateNumber($angle, 0, 180, $name.'.angle');
        if ($angle !== null && $angle !== '' && ! (bool) ($configuration['angle_enabled'] ?? true)) {
            $this->invalid($name.'.angle', 'Угол отключён для этой строки в каталоге.');
        }
        $this->validateChoice($row['tone'] ?? null, $configuration['tone_options'] ?? ['normal', 'hypotone', 'hypertone', 'гипотонус', 'гипертонус'], $name.'.tone');
        $this->validateChoice($row['finding'] ?? null, $configuration['finding_options'] ?? [], $name.'.finding');
        $this->validateChoice($row['answer'] ?? null, $configuration['answer_options'] ?? ['no', 'yes', 'нет', 'да'], $name.'.answer');
        $this->validateChoice($row['deviation'] ?? null, $configuration['deviation_options'] ?? [], $name.'.deviation');
        if (array_key_exists('marked', $row) && $row['marked'] !== null && ! is_bool($row['marked']) && ! in_array($row['marked'], [0, 1, '0', '1'], true)) {
            $this->invalid($name.'.marked', 'Отметка строки должна быть логическим значением.');
        }
        foreach (['cause_ids', 'allowed_cause_ids'] as $field) {
            if (! array_key_exists($field, $row) || $row[$field] === null || $row[$field] === []) {
                continue;
            }
            if (! is_array($row[$field]) || collect($row[$field])->contains(static fn (mixed $id): bool => ! is_numeric($id) || (int) $id < 1)) {
                $this->invalid($name.'.'.$field, 'Причины строки должны быть выбраны из справочника.');
            }
        }
    }

    private function validateIndex(mixed $value, mixed $enabled, string $name): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_numeric($value) || (float) $value !== (float) (int) $value || (int) $value < 0 || (int) $value > 3) {
            $this->invalid($name, 'Индекс должен быть от 0 до 3.');
        }
        if (! (bool) $enabled) {
            $this->invalid($name, 'Индекс отключён для этой строки.');
        }
    }

    private function validateNumber(mixed $value, float $min, float $max, string $name): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            $this->invalid($name, 'Значение выходит за допустимый диапазон.');
        }
    }

    /** @param mixed $options */
    private function validateChoice(mixed $value, mixed $options, string $name): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $allowed = $this->optionValues($options);
        if ($allowed !== [] && ! in_array((string) $value, $allowed, true)) {
            $this->invalid($name, 'Выбранное значение отсутствует в настройке каталога.');
        }
    }

    /** @return list<string> */
    private function optionValues(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }
        $values = [];
        foreach ($options as $key => $option) {
            if (is_array($option)) {
                $value = $option['value'] ?? $option['key'] ?? null;
            } elseif (is_string($key)) {
                $value = $key;
            } else {
                $value = $option;
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                $values[] = (string) $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages(['test_results' => $field.': '.$message]);
    }

    /** @param array<string, mixed> $row */
    private function hasFinding(array $row): bool
    {
        $finding = mb_strtolower(trim((string) ($row['finding'] ?? '')), 'UTF-8');

        return $finding !== '' && ! in_array($finding, ['normal', 'none', 'нет', 'норма'], true);
    }
}
