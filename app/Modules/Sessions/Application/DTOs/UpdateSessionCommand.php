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
            pain: self::normalizeOptionalValue($data['pain'], 'pain'),
            tests: self::normalizeOptionalValue($data['tests'], 'tests'),
            observations: self::normalizeOptionalValue($data['observations'], 'observations'),
            rootCauseHypothesis: self::normalizeOptionalValue($data['root_cause_hypothesis'], 'root_cause_hypothesis'),
            protocol: self::normalizeOptionalValue($data['protocol'], 'protocol'),
            result: self::normalizeOptionalValue($data['result'], 'result'),
        );
    }

    private static function normalizeOptionalValue(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return trim($value);
        }

        throw ValidationException::withMessages([
            $field => 'The "'.$field.'" field must be a string or null.',
        ]);
    }
}
