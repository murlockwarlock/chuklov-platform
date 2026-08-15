<?php

namespace App\Modules\Sessions\Application\DTOs;

use DateTimeInterface;

final readonly class MedicalSessionData
{
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $clientId,
        public int $specialistId,
        public ?int $bookingId,
        public ?string $pain,
        public ?string $tests,
        public ?string $observations,
        public ?string $rootCauseHypothesis,
        public ?string $protocol,
        public ?string $result,
        public int $encryptionKeyVersion = 1,
        public ?DateTimeInterface $occurredAt = null,
        public ?DateTimeInterface $createdAt = null,
        public ?DateTimeInterface $updatedAt = null,
    ) {}

    public function occurredAtAtom(): ?string
    {
        return $this->occurredAt?->format(DateTimeInterface::ATOM);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organizationId,
            'client_id' => $this->clientId,
            'specialist_id' => $this->specialistId,
            'booking_id' => $this->bookingId,
            'pain' => $this->pain,
            'tests' => $this->tests,
            'observations' => $this->observations,
            'root_cause_hypothesis' => $this->rootCauseHypothesis,
            'protocol' => $this->protocol,
            'result' => $this->result,
            'encryption_key_version' => $this->encryptionKeyVersion,
            'occurred_at' => $this->occurredAtAtom(),
            'updated_at' => $this->updatedAt?->format(DateTimeInterface::ATOM),
        ];
    }
}
