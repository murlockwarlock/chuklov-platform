<?php

namespace App\Modules\AI\Domain\ValueObjects;

final readonly class AiParameterConfig
{
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
            temperature: (float) ($data['temperature'] ?? 0.2),
            topP: (float) ($data['top_p'] ?? 0.9),
            maxTokens: (int) ($data['max_tokens'] ?? 4096),
            frequencyPenalty: (float) ($data['frequency_penalty'] ?? 0.0),
            presencePenalty: (float) ($data['presence_penalty'] ?? 0.0),
            timeoutSeconds: (int) ($data['timeout_seconds'] ?? 60),
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
}
