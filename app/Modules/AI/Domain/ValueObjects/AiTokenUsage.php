<?php

namespace App\Modules\AI\Domain\ValueObjects;

final readonly class AiTokenUsage
{
    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $totalTokens = 0,
        public int $cacheWriteInputTokens = 0,
        public int $cacheReadInputTokens = 0,
        public int $reasoningTokens = 0,
        public string $usageSource = 'estimated',
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $prompt = (int) ($data['prompt_tokens'] ?? $data['input_tokens'] ?? 0);
        $completion = (int) ($data['completion_tokens'] ?? $data['output_tokens'] ?? 0);
        $total = (int) ($data['total_tokens'] ?? ($prompt + $completion));
        $usageSource = (string) ($data['usage_source'] ?? 'estimated');

        if (! in_array($usageSource, ['provider_reported', 'estimated'], true)) {
            $usageSource = 'estimated';
        }

        return new self(
            promptTokens: $prompt,
            completionTokens: $completion,
            totalTokens: $total,
            cacheWriteInputTokens: max(0, (int) ($data['cache_write_input_tokens'] ?? 0)),
            cacheReadInputTokens: max(0, (int) ($data['cache_read_input_tokens'] ?? 0)),
            reasoningTokens: max(0, (int) ($data['reasoning_tokens'] ?? 0)),
            usageSource: $usageSource,
        );
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
            'cache_write_input_tokens' => $this->cacheWriteInputTokens,
            'cache_read_input_tokens' => $this->cacheReadInputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'usage_source' => $this->usageSource,
        ];
    }
}
