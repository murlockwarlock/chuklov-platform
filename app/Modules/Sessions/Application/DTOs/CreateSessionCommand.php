<?php

namespace App\Modules\Sessions\Application\DTOs;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

final readonly class CreateSessionCommand
{
    public function __construct(
        public int $clientId,
        public int $specialistId,
        public DateTimeInterface $occurredAt,
        public ?int $bookingId = null,
        public ?string $pain = null,
        public ?string $tests = null,
        public ?string $observations = null,
        public ?string $rootCauseHypothesis = null,
        public ?string $protocol = null,
        public ?string $result = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawOccurredAt = $data['occurred_at'] ?? null;
        $occurredAt = match (true) {
            $rawOccurredAt instanceof DateTimeInterface => Carbon::instance($rawOccurredAt)->utc()->toDateTimeImmutable(),
            is_string($rawOccurredAt) && $rawOccurredAt !== '' => Carbon::parse($rawOccurredAt)->utc()->toDateTimeImmutable(),
            default => Carbon::now('UTC')->toDateTimeImmutable(),
        };

        return new self(
            clientId: isset($data['client_id']) && is_numeric($data['client_id']) ? (int) $data['client_id'] : 0,
            specialistId: isset($data['specialist_id']) && is_numeric($data['specialist_id']) ? (int) $data['specialist_id'] : 0,
            occurredAt: $occurredAt,
            bookingId: isset($data['booking_id']) && is_numeric($data['booking_id']) ? (int) $data['booking_id'] : null,
            pain: isset($data['pain']) && is_string($data['pain']) ? trim($data['pain']) : null,
            tests: isset($data['tests']) && is_string($data['tests']) ? trim($data['tests']) : null,
            observations: isset($data['observations']) && is_string($data['observations']) ? trim($data['observations']) : null,
            rootCauseHypothesis: isset($data['root_cause_hypothesis']) && is_string($data['root_cause_hypothesis']) ? trim($data['root_cause_hypothesis']) : null,
            protocol: isset($data['protocol']) && is_string($data['protocol']) ? trim($data['protocol']) : null,
            result: isset($data['result']) && is_string($data['result']) ? trim($data['result']) : null,
        );
    }

    public function occurredAtUtc(): DateTimeImmutable
    {
        return Carbon::instance($this->occurredAt)->utc()->toDateTimeImmutable();
    }
}
