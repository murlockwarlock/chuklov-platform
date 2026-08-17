<?php

namespace App\Modules\AI\Infrastructure\Engine;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class DynamicWorkflowAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /**
     * @param  iterable<Tool>  $agentTools
     */
    public function __construct(
        public string $instructionsText = '',
        public iterable $agentTools = [],
        public ?string $defaultProvider = null,
        public ?string $defaultModel = null,
        public ?int $resolvedMaxTokens = null,
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->instructionsText !== ''
            ? $this->instructionsText
            : 'Provide accurate, clinical-grade assistant responses.';
    }

    public function messages(): iterable
    {
        return [];
    }

    /**
     * @return iterable<Tool>
     */
    public function tools(): iterable
    {
        return $this->agentTools;
    }

    /**
     * @param  iterable<Tool>  $tools
     */
    public function withTools(iterable $tools): static
    {
        $clone = clone $this;
        $clone->agentTools = $tools;

        return $clone;
    }

    public function withMaxTokens(?int $maxTokens): static
    {
        $clone = clone $this;
        $clone->resolvedMaxTokens = $maxTokens;

        return $clone;
    }

    public function maxTokens(): ?int
    {
        return $this->resolvedMaxTokens;
    }
}
