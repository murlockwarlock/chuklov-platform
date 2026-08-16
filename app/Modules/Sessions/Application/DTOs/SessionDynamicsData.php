<?php

namespace App\Modules\Sessions\Application\DTOs;

final readonly class SessionDynamicsData
{
    public function __construct(
        public MedicalSessionData $current,
        public ?MedicalSessionData $previous,
        public string $currentSpecialist,
        public string $currentBooking,
        public ?string $previousSpecialist,
        public ?string $previousBooking,
    ) {}

    /** @return list<array<string, mixed>> */
    public function comparison(): array
    {
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

    /** @return array<string, mixed> */
    private function item(string $period, MedicalSessionData $session, string $specialist, string $booking): array
    {
        return [
            'period' => $period,
            'occurred_at' => $session->occurredAt?->format('d.m.Y H:i') ?? '—',
            'specialist' => $specialist,
            'booking' => $booking,
            'pain' => $session->pain,
            'tests' => $session->tests,
            'observations' => $session->observations,
            'root_cause_hypothesis' => $session->rootCauseHypothesis,
            'protocol' => $session->protocol,
            'result' => $session->result,
        ];
    }
}
