<?php

namespace App\Modules\Sessions\Application;

use App\Modules\Sessions\Application\DTOs\MedicalClinicalEvaluation;
use App\Modules\Sessions\Domain\Enums\MedicalTestType;

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
        return $this->rowIndexEvaluation($configuration, $before, $after);
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
            $finding = strtolower(trim((string) ($row['finding'] ?? '')));

            return ($finding !== '' && ! in_array($finding, ['normal', 'none', 'нет', 'норма'], true))
                || ($row['marked'] ?? false) === true
                || $row['index'] > 0;
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
        $allowed = is_array($rules['allowed_sequences'] ?? null) ? $rules['allowed_sequences'] : [];
        $countTransitions = (bool) ($rules['broken_transition_count'] ?? false);
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
            static fn (array $row): bool => ($row['marked'] ?? false) === true
                || in_array(strtolower(trim((string) ($row['answer'] ?? ''))), ['yes', 'да', 'true', '1'], true),
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
        $base = $this->rowIndexEvaluation($configuration, $before, $after, allowTone: true);
        $warning = false;
        foreach ([$before, $after] as $phase) {
            foreach ($this->findingRows($phase) as $row) {
                if (in_array(strtolower(trim((string) ($row['tone'] ?? ''))), ['hypotone', 'гипотонус'], true)) {
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
            warnings: $warning ? ['Вытяжение в этом отделе не делать.'] : [],
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
        $rowsBefore = $this->findingRows($before);
        $rowsAfter = $this->findingRows($after);
        $isViolation = static function (array $row): bool {
            if (($row['index'] ?? null) !== null) {
                return (int) $row['index'] > 0;
            }

            return ($row['marked'] ?? false) === true
                || in_array(strtolower(trim((string) ($row['deviation'] ?? ''))), ['yes', 'да', 'true', '1'], true);
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
            static fn (array $row): bool => ($row['marked'] ?? false) === true
                || in_array(strtolower(trim((string) ($row['answer'] ?? ''))), ['yes', 'да', 'true', '1'], true),
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
        $needsThreshold = ($before !== null && array_key_exists('energy', $before)) || ($after !== null && array_key_exists('energy', $after));
        $needsTor = ($before !== null && array_key_exists('tor_direction', $before)) || ($after !== null && array_key_exists('tor_direction', $after));
        $failClosed = ($needsThreshold && ! is_numeric($threshold)) || ($needsTor && ! is_string($normalTor));
        $warnings = [];
        if ($failClosed) {
            $warnings[] = 'Для этого теста не настроена обязательная клиническая норма; подсчёт отключён.';
        }

        $beforeResult = $this->energyPhase($before, $threshold, $normalTor, $chakraViolation);
        $afterResult = $this->energyPhase($after, $threshold, $normalTor, $chakraViolation);

        return new MedicalClinicalEvaluation(
            beforeCount: $failClosed ? 0 : count($beforeResult),
            afterCount: $failClosed ? 0 : count($afterResult),
            beforeViolationKeys: $failClosed ? [] : $beforeResult,
            afterViolationKeys: $failClosed ? [] : $afterResult,
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
            if ($index !== null && in_array((int) $index, array_map('intval', $violationIndexes), true)) {
                return true;
            }
            if ($allowTone && in_array(strtolower(trim((string) ($row['tone'] ?? ''))), $toneValues, true)) {
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
        $rows = $phase['rows'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param array<string, mixed>|null $phase
     * @return list<array<string, mixed>>
     */
    private function nestedRows(?array $phase, string $key): array
    {
        $rows = $phase[$key] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
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
        if (count($sequence) < 2) {
            return ['keys' => []];
        }
        $normalized = array_map(static fn (mixed $value): string => strtoupper(trim((string) $value)), $sequence);
        if (in_array($normalized, $allowed, true)) {
            return ['keys' => []];
        }
        if (! $countTransitions) {
            return ['keys' => ['formula']];
        }

        $keys = [];
        for ($index = 1; $index < count($normalized); $index++) {
            if ($normalized[$index] === $normalized[$index - 1]) {
                $keys[] = 'transition:'.($index - 1);
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
        if (is_numeric($threshold) && is_numeric($phase['energy'] ?? null) && (float) $phase['energy'] < (float) $threshold) {
            $keys[] = 'energy';
        }
        if ($chakraViolation) {
            foreach (is_array($phase['chakras'] ?? null) ? $phase['chakras'] : [] as $chakra) {
                if (is_array($chakra) && ($chakra['marked'] ?? false) === true) {
                    $keys[] = 'chakra:'.trim((string) ($chakra['key'] ?? count($keys)));
                }
            }
        }
        if (is_string($normalTor) && is_string($phase['tor_direction'] ?? null) && $phase['tor_direction'] !== $normalTor) {
            $keys[] = 'tor_direction';
        }

        return array_values(array_unique($keys));
    }
}
