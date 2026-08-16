<?php

namespace App\Modules\AI\Infrastructure\Engine;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class DynamicWorkflowAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /**
     * @param  iterable<mixed>  $agentTools
     */
    public function __construct(
        public string $instructionsText = '',
        public iterable $agentTools = [],
        public ?string $defaultProvider = null,
        public ?string $defaultModel = null,
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

    public function tools(): iterable
    {
        return $this->agentTools;
    }
}
