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
        public ?string $expectedSnapshot = null,
        public ?int $painVas = null,
        public ?bool $painVasProvided = null,
        /** @var array<int, mixed>|null */
        public ?array $pains = null,
        public bool $painsProvided = false,
        public ?string $subjectiveFeelings = null,
        public ?string $visualPostureDiagnostics = null,
        public ?bool $subjectiveFeelingsProvided = null,
        public ?bool $visualPostureDiagnosticsProvided = null,
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
            expectedSnapshot: isset($data['expected_snapshot']) && is_string($data['expected_snapshot']) ? $data['expected_snapshot'] : null,
            painVas: self::normalizePainVas($data),
            painVasProvided: array_key_exists('pain_vas', $data),
            pains: array_key_exists('pains', $data) && is_array($data['pains']) ? array_values($data['pains']) : null,
            painsProvided: array_key_exists('pains', $data),
            subjectiveFeelings: self::normalizeOptionalValue($data['subjective_feelings'] ?? null, 'subjective_feelings'),
            visualPostureDiagnostics: self::normalizeOptionalValue($data['visual_posture_diagnostics'] ?? null, 'visual_posture_diagnostics'),
            subjectiveFeelingsProvided: array_key_exists('subjective_feelings', $data),
            visualPostureDiagnosticsProvided: array_key_exists('visual_posture_diagnostics', $data),
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

    public function shouldUpdatePainVas(): bool
    {
        return $this->painVasProvided ?? $this->painVas !== null;
    }

    public function shouldUpdateSubjectiveFeelings(): bool
    {
        return $this->subjectiveFeelingsProvided ?? $this->subjectiveFeelings !== null;
    }

    public function shouldUpdateVisualPostureDiagnostics(): bool
    {
        return $this->visualPostureDiagnosticsProvided ?? $this->visualPostureDiagnostics !== null;
    }

    /** @param array<string, mixed> $data */
    private static function normalizePainVas(array $data): ?int
    {
        if (! array_key_exists('pain_vas', $data) || $data['pain_vas'] === null || $data['pain_vas'] === '') {
            return null;
        }

        if (is_int($data['pain_vas'])) {
            return $data['pain_vas'];
        }

        if ((is_float($data['pain_vas']) || is_string($data['pain_vas']))
            && is_numeric($data['pain_vas'])
            && (float) $data['pain_vas'] === (float) (int) $data['pain_vas']) {
            return (int) $data['pain_vas'];
        }

        throw ValidationException::withMessages([
            'pain_vas' => 'Укажите целое значение боли от 0 до 10.',
        ]);
    }
}
