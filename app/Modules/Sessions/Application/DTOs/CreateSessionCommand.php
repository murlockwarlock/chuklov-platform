<?php

namespace App\Modules\Sessions\Application\DTOs;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final readonly class CreateSessionCommand
{
    public function __construct(
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
            default => throw ValidationException::withMessages([
                'occurred_at' => 'The "occurred_at" field is required and must be a valid date or date-time value.',
            ]),
        };

        return new self(
            specialistId: isset($data['specialist_id']) && is_numeric($data['specialist_id']) ? (int) $data['specialist_id'] : 0,
            occurredAt: $occurredAt,
            bookingId: isset($data['booking_id']) && is_numeric($data['booking_id']) ? (int) $data['booking_id'] : null,
            pain: self::optionalTrimmedString($data, 'pain'),
            tests: self::optionalTrimmedString($data, 'tests'),
            observations: self::optionalTrimmedString($data, 'observations'),
            rootCauseHypothesis: self::optionalTrimmedString($data, 'root_cause_hypothesis'),
            protocol: self::optionalTrimmedString($data, 'protocol'),
            result: self::optionalTrimmedString($data, 'result'),
        );
    }

    public function occurredAtUtc(): DateTimeImmutable
    {
        return Carbon::instance($this->occurredAt)->utc()->toDateTimeImmutable();
    }

    /** @param  array<string, mixed>  $data */
    private static function optionalTrimmedString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        if ($data[$key] === null) {
            return null;
        }

        if (is_string($data[$key])) {
            return trim($data[$key]);
        }

        return null;
    }
}
