<?php

namespace App\Modules\AI\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class AiParameterConfig
{
    private const MAX_TOKENS = 8192;

    private const MAX_TIMEOUT_SECONDS = 120;

    public function __construct(
        public float $temperature = 0.2,
        public float $topP = 0.9,
        public int $maxTokens = 4096,
        public float $frequencyPenalty = 0.0,
        public float $presencePenalty = 0.0,
        public int $timeoutSeconds = 60,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            temperature: self::decimal($data['temperature'] ?? 0.2, 'temperature', 0.0, 2.0),
            topP: self::decimal($data['top_p'] ?? 0.9, 'top_p', 0.0, 1.0),
            maxTokens: self::integer($data['max_tokens'] ?? 4096, 'max_tokens', 1, self::MAX_TOKENS),
            frequencyPenalty: self::decimal($data['frequency_penalty'] ?? 0.0, 'frequency_penalty', -2.0, 2.0),
            presencePenalty: self::decimal($data['presence_penalty'] ?? 0.0, 'presence_penalty', -2.0, 2.0),
            timeoutSeconds: self::integer($data['timeout_seconds'] ?? 60, 'timeout_seconds', 1, self::MAX_TIMEOUT_SECONDS),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'max_tokens' => $this->maxTokens,
            'frequency_penalty' => $this->frequencyPenalty,
            'presence_penalty' => $this->presencePenalty,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }

    private static function decimal(mixed $value, string $key, float $minimum, float $maximum): float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new InvalidArgumentException("{$key} must be a number.");
        }

        if (is_string($value) && ($value === '' || ! is_numeric($value))) {
            throw new InvalidArgumentException("{$key} must be a number.");
        }

        $number = (float) $value;
        if (! is_finite($number) || $number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException("{$key} must be between {$minimum} and {$maximum}.");
        }

        return $number;
    }

    private static function integer(mixed $value, string $key, int $minimum, int $maximum): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_float($value) && is_finite($value) && floor($value) === $value) {
            $number = (int) $value;
        } elseif (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            $number = (int) $value;
        } else {
            throw new InvalidArgumentException("{$key} must be an integer.");
        }

        if ($number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException("{$key} must be between {$minimum} and {$maximum}.");
        }

        return $number;
    }
}
