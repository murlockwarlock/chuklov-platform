<?php

namespace App\Modules\Sessions\Application\DTOs;

use App\Modules\MedicalProfiles\Application\DTOs\MedicalPainData;
use App\Modules\Sessions\Application\GetSessionDynamics;
use Carbon\CarbonImmutable;

final readonly class SessionDynamicsData
{
    /** @var list<array{key:string,label:string,unit:string,source:string}> */
    private const CHART_METRICS = [
        ['key' => 'sys', 'label' => 'SYS', 'unit' => 'мм рт. ст.', 'source' => 'vitals'],
        ['key' => 'dia', 'label' => 'DIA', 'unit' => 'мм рт. ст.', 'source' => 'vitals'],
        ['key' => 'pulse', 'label' => 'Пульс', 'unit' => 'уд/мин', 'source' => 'vitals'],
        ['key' => 'pi', 'label' => 'Индекс перфузии', 'unit' => '%', 'source' => 'vitals'],
        ['key' => 'spo2', 'label' => 'SpO2', 'unit' => '%', 'source' => 'vitals'],
    ];

    /**
     * @param list<array{period:string,data:MedicalSessionData,specialist:string,booking:string}> $history
     */
    public function __construct(
        public MedicalSessionData $current,
        public ?MedicalSessionData $previous,
        public string $currentSpecialist,
        public string $currentBooking,
        public ?string $previousSpecialist,
        public ?string $previousBooking,
        public string $timezone = 'UTC',
        public array $history = [],
    ) {}

    /** @return list<array<string, mixed>> */
    public function comparison(): array
    {
        if ($this->history !== []) {
            return array_map(
                fn (array $snapshot): array => $this->item(
                    $snapshot['period'],
                    $snapshot['data'],
                    $snapshot['specialist'],
                    $snapshot['booking'],
                ),
                $this->history,
            );
        }

        $items = [$this->item(
            'Текущий сеанс',
            $this->current,
            $this->currentSpecialist,
            $this->currentBooking,
        )];

        if ($this->previous !== null) {
            $items[] = $this->item(
                'Предыдущий сеанс',
                $this->previous,
                $this->previousSpecialist ?? '—',
                $this->previousBooking ?? 'Без записи на приём',
            );
        }

        return $items;
    }

    /** @return array{scope:string,interpretation_allowed:false,sessions:list<array<string,mixed>>} */
    public function analysisPayload(): array
    {
        $history = $this->history !== []
            ? $this->history
            : array_values(array_filter([
                [
                    'period' => 'Текущий сеанс',
                    'data' => $this->current,
                    'specialist' => $this->currentSpecialist,
                    'booking' => $this->currentBooking,
                ],
                $this->previous === null ? null : [
                    'period' => 'Предыдущий сеанс',
                    'data' => $this->previous,
                    'specialist' => $this->previousSpecialist ?? '—',
                    'booking' => $this->previousBooking ?? 'Без записи на приём',
                ],
            ]));

        return [
            'scope' => 'confirmed_session_facts',
            'interpretation_allowed' => false,
            'sessions' => array_map(
                fn (array $snapshot): array => $this->analysisItem($snapshot['data']),
                $history,
            ),
        ];
    }

    /**
     * @return array{
     *     sessions:list<array{session_id:int,period:string,date:string,full_date:string,is_current:bool}>,
     *     metrics:list<array<string,mixed>>,
     *     pains:list<array<string,mixed>>,
     *     tests:list<array<string,mixed>>
     * }
     */
    public function chartData(): array
    {
        $snapshots = $this->chartSnapshots();
        $sessions = array_map(static fn (array $snapshot): array => [
            'session_id' => $snapshot['data']->id,
            'period' => $snapshot['period'],
            'date' => $snapshot['date'],
            'full_date' => $snapshot['full_date'],
            'is_current' => $snapshot['is_current'],
        ], $snapshots);

        $metrics = [];
        foreach (self::CHART_METRICS as $definition) {
            $series = $this->numericSeries(
                key: $definition['key'],
                label: $definition['label'],
                unit: $definition['unit'],
                rows: array_map(static fn (array $snapshot): array => [
                    'session_id' => $snapshot['data']->id,
                    'date' => $snapshot['date'],
                    'full_date' => $snapshot['full_date'],
                    'before' => self::measurementValue($snapshot['data']->measurements, 'before', $definition['key']),
                    'after' => self::measurementValue($snapshot['data']->measurements, 'after', $definition['key']),
                ], $snapshots),
            );

            if ($series !== null) {
                $metrics[] = $series;
            }
        }

        return [
            'sessions' => $sessions,
            'metrics' => $metrics,
            'pains' => $this->painSeries($snapshots),
            'tests' => $this->testGroups($snapshots),
        ];
    }

    /**
     * @return list<array{period:string,data:MedicalSessionData,specialist:string,booking:string,date:string,full_date:string,is_current:bool}>
     */
    private function chartSnapshots(): array
    {
        $history = $this->history !== []
            ? $this->history
            : array_values(array_filter([
                [
                    'period' => 'Текущий сеанс',
                    'data' => $this->current,
                    'specialist' => $this->currentSpecialist,
                    'booking' => $this->currentBooking,
                ],
                $this->previous === null ? null : [
                    'period' => 'Предыдущий сеанс',
                    'data' => $this->previous,
                    'specialist' => $this->previousSpecialist ?? '—',
                    'booking' => $this->previousBooking ?? 'Без записи на приём',
                ],
            ]));
        $chronological = array_slice(array_reverse($history), -GetSessionDynamics::MAX_HISTORY);

        return array_map(function (array $snapshot): array {
            $occurredAt = $snapshot['data']->occurredAt;
            $localized = $occurredAt === null
                ? null
                : CarbonImmutable::instance($occurredAt)->setTimezone($this->timezone);

            return [
                ...$snapshot,
                'date' => $localized?->format('d.m') ?? '—',
                'full_date' => $localized?->format('d.m.Y H:i') ?? '—',
                'is_current' => $snapshot['data']->id === $this->current->id,
            ];
        }, $chronological);
    }

    private static function measurementValue(?array $measurements, string $phase, string $key): int|float|null
    {
        if (! is_array($measurements) || ($phase === 'after' && ! ($measurements['after_enabled'] ?? false))) {
            return null;
        }

        $vitals = $measurements[$phase]['vitals'] ?? null;

        return is_array($vitals) ? self::numericValue($vitals[$key] ?? null) : null;
    }

    /**
     * @param list<array{session_id:int,date:string,full_date:string,before:mixed,after:mixed}> $rows
     * @return array<string,mixed>|null
     */
    private function numericSeries(string $key, string $label, string $unit, array $rows): ?array
    {
        $values = [];
        foreach ($rows as $row) {
            foreach (['before', 'after'] as $phase) {
                $value = self::numericValue($row[$phase]);
                if ($value !== null) {
                    $values[] = $value;
                }
            }
        }

        if ($values === []) {
            return null;
        }

        $min = min($values);
        $max = max($values);
        $points = ['before' => [], 'after' => []];
        $rowCount = count($rows);

        foreach ($rows as $index => $row) {
            foreach (['before', 'after'] as $phase) {
                $value = self::numericValue($row[$phase]);
                $point = [
                    'session_id' => $row['session_id'],
                    'date' => $row['date'],
                    'full_date' => $row['full_date'],
                    'value' => $value,
                    'value_label' => $value === null ? null : self::valueLabel($value),
                ];

                if ($value !== null) {
                    $point['x'] = self::plotX($index, $rowCount);
                    $point['y'] = self::plotY($value, $min, $max);
                }

                $points[$phase][] = $point;
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'unit' => $unit,
            'min_label' => self::valueLabel($min),
            'max_label' => self::valueLabel($max),
            'points' => $points,
            'segments' => [
                'before' => self::segments($points['before']),
                'after' => self::segments($points['after']),
            ],
        ];
    }

    /**
     * @param list<array{period:string,data:MedicalSessionData,specialist:string,booking:string,date:string,full_date:string,is_current:bool}> $snapshots
     * @return list<array<string,mixed>>
     */
    private function painSeries(array $snapshots): array
    {
        $groups = [];
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot['data']->pains as $pain) {
                $lineageKey = trim($pain->lineageKey);
                if ($lineageKey === '') {
                    continue;
                }

                $groups[$lineageKey] ??= [
                    'label' => $pain->name !== '' ? $pain->name : 'Оценка боли',
                    'rows' => array_map(static fn (array $item): array => [
                        'session_id' => $item['data']->id,
                        'date' => $item['date'],
                        'full_date' => $item['full_date'],
                        'before' => null,
                        'after' => null,
                    ], $snapshots),
                ];

                foreach ($groups[$lineageKey]['rows'] as &$row) {
                    if ($row['session_id'] !== $snapshot['data']->id) {
                        continue;
                    }
                    $row['before'] = $pain->beforeScore;
                    $row['after'] = $pain->afterScore;
                    break;
                }
                unset($row);
            }
        }

        $series = [];
        foreach ($groups as $lineageKey => $group) {
            $chart = $this->numericSeries('pain:'.$lineageKey, $group['label'], '/10', $group['rows']);
            if ($chart !== null) {
                $series[] = $chart;
            }
        }

        return $series;
    }

    /**
     * @param list<array{period:string,data:MedicalSessionData,specialist:string,booking:string,date:string,full_date:string,is_current:bool}> $snapshots
     * @return list<array<string,mixed>>
     */
    private function testGroups(array $snapshots): array
    {
        $groups = [];
        foreach ($snapshots as $snapshot) {
            foreach ($snapshot['data']->testResults as $test) {
                if ($test->beforeLabel === null && $test->afterLabel === null) {
                    continue;
                }

                $signature = self::testSignature($test);
                $groupKey = $signature === null
                    ? 'session:'.$snapshot['data']->id.':test:'.$test->testId
                    : 'test:'.$test->testId.':'.$signature;
                $groups[$groupKey] ??= [
                    'key' => $groupKey,
                    'definition_id' => $test->testId,
                    'signature' => $signature,
                    'label' => $test->testName,
                    'note' => $signature === null
                        ? 'Результат показан отдельно: версия методики не сохранена.'
                        : null,
                    'sessions' => [],
                ];
                $groups[$groupKey]['sessions'][] = [
                    'session_id' => $snapshot['data']->id,
                    'period' => $snapshot['period'],
                    'date' => $snapshot['date'],
                    'full_date' => $snapshot['full_date'],
                    'is_current' => $snapshot['is_current'],
                    'before' => $test->beforeLabel,
                    'after' => $test->afterLabel,
                ];
            }
        }

        $definitionGroupCounts = [];
        foreach ($groups as $group) {
            $definitionGroupCounts[$group['definition_id']] = ($definitionGroupCounts[$group['definition_id']] ?? 0) + 1;
        }

        foreach ($groups as &$group) {
            if ($definitionGroupCounts[$group['definition_id']] > 1 && $group['note'] === null) {
                $group['note'] = 'Результаты с разной версией методики не объединены.';
            }
            unset($group['definition_id'], $group['signature']);
        }
        unset($group);

        return array_values($groups);
    }

    private static function testSignature(MedicalSessionTestData $test): ?string
    {
        if ($test->structuredPayload === null) {
            return 'catalog';
        }

        $type = $test->structuredPayload['type'] ?? null;
        $version = $test->structuredPayload['version'] ?? null;
        if (! is_scalar($type) || ! is_scalar($version)) {
            return null;
        }

        $units = [];
        foreach (['unit', 'measurement_unit'] as $key) {
            if (is_scalar($test->structuredPayload[$key] ?? null)) {
                $units[] = (string) $test->structuredPayload[$key];
            }
        }
        foreach (['before', 'after'] as $phase) {
            $phaseData = $test->structuredPayload[$phase] ?? null;
            if (! is_array($phaseData)) {
                continue;
            }
            foreach (['unit', 'measurement_unit'] as $key) {
                if (is_scalar($phaseData[$key] ?? null)) {
                    $units[] = (string) $phaseData[$key];
                }
            }
        }

        return implode(':', [
            'structured',
            (string) $type,
            (string) $version,
            implode('|', array_values(array_unique($units))),
        ]);
    }

    /**
     * @param list<array<string,mixed>> $points
     * @return list<list<array{x:float,y:float}>>
     */
    private static function segments(array $points): array
    {
        $segments = [];
        $segment = [];
        foreach ($points as $point) {
            if (! array_key_exists('x', $point) || ! array_key_exists('y', $point)) {
                if ($segment !== []) {
                    $segments[] = $segment;
                    $segment = [];
                }
                continue;
            }

            $segment[] = ['x' => (float) $point['x'], 'y' => (float) $point['y']];
        }
        if ($segment !== []) {
            $segments[] = $segment;
        }

        return $segments;
    }

    private static function plotX(int $index, int $count): float
    {
        if ($count <= 1) {
            return 320.0;
        }

        return 28.0 + ($index * (584.0 / ($count - 1)));
    }

    private static function plotY(int|float $value, int|float $min, int|float $max): float
    {
        $range = (float) $max - (float) $min;
        if ($range === 0.0) {
            return 88.0;
        }

        return 16.0 + (1.0 - (((float) $value - (float) $min) / $range)) * 144.0;
    }

    private static function numericValue(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (! is_string($value) || ! is_numeric($value)) {
            return null;
        }

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    private static function valueLabel(int|float $value): string
    {
        return (string) $value;
    }

    /** @return array<string, mixed> */
    private function item(string $period, MedicalSessionData $session, string $specialist, string $booking): array
    {
        return [
            'period' => $period,
            'occurred_at' => $session->occurredAt === null
                ? '—'
                : CarbonImmutable::instance($session->occurredAt)->setTimezone($this->timezone)->format('d.m.Y H:i'),
            'specialist' => $specialist,
            'booking' => $booking,
            'pain' => $session->pain,
            'pain_vas' => $session->painVas,
            'pain_scores' => self::painScores($session),
            'tests' => $session->tests,
            'test_results' => self::testResults($session),
            'measurements_before' => self::measurementValues($session->measurements, 'before'),
            'measurements_after' => self::measurementValues($session->measurements, 'after'),
            'observations' => $session->observations,
            'subjective_feelings' => $session->subjectiveFeelings,
            'visual_posture_diagnostics' => $session->visualPostureDiagnostics,
            'root_cause_hypothesis' => $session->rootCauseHypothesis,
            'protocol' => $session->protocol,
            'result' => $session->result,
        ];
    }

    /** @return array<string, mixed> */
    private function analysisItem(MedicalSessionData $session): array
    {
        return [
            'session_id' => $session->id,
            'occurred_at' => $session->occurredAtAtom(),
            'measurements' => $session->measurements,
            'pain' => $session->pain,
            'pain_vas' => $session->painVas,
            'pain_scores' => array_map(
                static fn (MedicalPainData $pain): array => $pain->toArray(),
                $session->pains,
            ),
            'test_results' => array_map(
                static fn (MedicalSessionTestData $test): array => $test->toArray(),
                $session->testResults,
            ),
            'observations' => $session->observations,
            'subjective_feelings' => $session->subjectiveFeelings,
            'visual_posture_diagnostics' => $session->visualPostureDiagnostics,
            'root_cause_hypothesis' => $session->rootCauseHypothesis,
            'protocol' => $session->protocol,
            'protocol_steps' => $session->protocolSteps,
            'result' => $session->result,
        ];
    }

    /** @return list<string> */
    private static function painScores(MedicalSessionData $session): array
    {
        return array_values(array_map(
            static fn (MedicalPainData $pain): string => implode('; ', [
                $pain->name,
                'до: '.self::displayValue($pain->beforeScore),
                'после: '.self::displayValue($pain->afterScore),
            ]),
            $session->pains,
        ));
    }

    /** @return list<string> */
    private static function testResults(MedicalSessionData $session): array
    {
        return array_values(array_map(
            static fn (MedicalSessionTestData $test): string => implode('; ', [
                $test->testName,
                'до: '.self::displayValue($test->beforeLabel),
                'после: '.self::displayValue($test->afterLabel),
            ]),
            $session->testResults,
        ));
    }

    /** @return list<string> */
    private static function measurementValues(?array $measurements, string $phase): array
    {
        $vitals = $measurements[$phase]['vitals'] ?? null;
        if (! is_array($vitals)) {
            return [];
        }

        $labels = [
            'sys' => 'SYS',
            'dia' => 'DIA',
            'pd' => 'PD',
            'map' => 'MAP',
            'pulse' => 'Пульс',
            'pi' => 'Индекс перфузии',
            'spo2' => 'Сатурация',
        ];

        $values = [];
        foreach ($labels as $key => $label) {
            if (! array_key_exists($key, $vitals) || $vitals[$key] === null) {
                continue;
            }

            $values[] = $label.': '.(string) $vitals[$key];
        }

        return $values;
    }

    private static function displayValue(mixed $value): string
    {
        return $value === null || $value === '' ? '—' : (string) $value;
    }
}
