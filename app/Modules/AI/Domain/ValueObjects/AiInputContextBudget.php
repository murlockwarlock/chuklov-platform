<?php

namespace App\Modules\AI\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class AiInputContextBudget
{
    public function __construct(
        public int $systemPromptTokens,
        public int $userPromptTokens,
        public int $maximumTokens,
        public string $estimator,
    ) {
        if ($this->systemPromptTokens < 0 || $this->userPromptTokens < 0 || $this->maximumTokens < 1 || $this->estimator === '') {
            throw new InvalidArgumentException('AI input context budget is invalid.');
        }
    }

    public function estimatedTokens(): int
    {
        return $this->systemPromptTokens + $this->userPromptTokens;
    }

    public function fits(): bool
    {
        return $this->estimatedTokens() <= $this->maximumTokens;
    }
}
