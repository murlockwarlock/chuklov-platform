<?php

namespace App\Modules\Sessions\Application\DTOs;

use Illuminate\Validation\ValidationException;

final readonly class UpdateSessionCommand
{
    public function __construct(
        public ?string $pain,
        public ?string $tests,
        public ?string $observations,
        public ?string $rootCauseHypothesis,
        public ?string $protocol,
        public ?string $result,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $required = [
            'pain',
            'tests',
            'observations',
            'root_cause_hypothesis',
            'protocol',
            'result',
        ];

        $missing = [];
        foreach ($required as $field) {
            if (! array_key_exists($field, $data)) {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                $missing[0] => 'The following clinical fields are required as a full snapshot: '.implode(', ', $missing).'.',
            ]);
        }

        return new self(
            pain: self::normalizeOptionalValue($data['pain']),
            tests: self::normalizeOptionalValue($data['tests']),
            observations: self::normalizeOptionalValue($data['observations']),
            rootCauseHypothesis: self::normalizeOptionalValue($data['root_cause_hypothesis']),
            protocol: self::normalizeOptionalValue($data['protocol']),
            result: self::normalizeOptionalValue($data['result']),
        );
    }

    private static function normalizeOptionalValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return trim($value);
        }

        return null;
    }
}
