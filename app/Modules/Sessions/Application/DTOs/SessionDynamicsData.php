<?php

namespace App\Modules\Sessions\Application\DTOs;

use App\Modules\MedicalProfiles\Application\DTOs\MedicalPainData;
use Carbon\CarbonImmutable;

final readonly class SessionDynamicsData
{
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
