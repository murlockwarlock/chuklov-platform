<?php

namespace App\Modules\AI\Domain\ValueObjects;

final readonly class AiTokenUsage
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $prompt = (int) ($data['prompt_tokens'] ?? $data['input_tokens'] ?? 0);
        $completion = (int) ($data['completion_tokens'] ?? $data['output_tokens'] ?? 0);
        $total = (int) ($data['total_tokens'] ?? ($prompt + $completion));

        return new self(
            promptTokens: $prompt,
            completionTokens: $completion,
            totalTokens: $total,
        );
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }
}
