<?php

namespace App\Modules\AI\Application\Services;

use App\Modules\AI\Application\Data\ContextAssemblyResult;
use App\Modules\AI\Domain\Contracts\AiPromptRendererInterface;
use App\Modules\AI\Domain\Registry\AiCapabilityDefinition;
use App\Modules\AI\Domain\Services\AiRuntimeLimits;
use App\Modules\AI\Domain\ValueObjects\AiContextPolicy;

final readonly class BoundedAiPromptContext
{
    public function __construct(
        private AiPromptRendererInterface $renderer,
    ) {}

    /** @return array{contextAssembly: ContextAssemblyResult, systemPrompt: string, userPrompt: string} */
    public function render(
        string $systemTemplate,
        string $userTemplate,
        ContextAssemblyResult $contextAssembly,
        AiCapabilityDefinition $capability,
        AiContextPolicy $contextPolicy,
    ): array {
        $variables = $contextAssembly->variables;
        [$systemPrompt, $userPrompt] = $this->prompts($systemTemplate, $userTemplate, $variables);

        if ($this->fits($systemPrompt, $userPrompt, $capability)) {
            return [
                'contextAssembly' => $contextAssembly,
                'systemPrompt' => $systemPrompt,
                'userPrompt' => $userPrompt,
            ];
        }

        if (is_string($variables['conversation_history'] ?? null)) {
            [$variables, $systemPrompt, $userPrompt] = $this->fitHistory(
                $systemTemplate,
                $userTemplate,
                $variables,
                $capability,
            );
        }

        $ragChunks = $contextAssembly->ragChunks;
        $provenanceSummary = $contextAssembly->provenanceSummary;
        $originalHealthContext = $variables['health_context'] ?? null;
        if (! $this->fits($systemPrompt, $userPrompt, $capability)
            && is_string($variables['health_context'] ?? null)) {
            [$variables, $systemPrompt, $userPrompt] = $this->fitHealthContext(
                $systemTemplate,
                $userTemplate,
                $variables,
                $capability,
            );
        }

        if (! $this->fits($systemPrompt, $userPrompt, $capability)
            && $contextPolicy->allowRagDegradation
            && ! $contextPolicy->requireGroundedRag
            && is_string($variables['rag_context'] ?? null)
            && $variables['rag_context'] !== '') {
            $variables['rag_context'] = '';
            $ragChunks = [];
            $provenanceSummary['rag_degraded'] = true;
            $provenanceSummary['rag_chunks_count'] = 0;
            [$variables, $systemPrompt, $userPrompt] = $this->fitHistory(
                $systemTemplate,
                $userTemplate,
                $variables,
                $capability,
            );
        }

        $boundedAssembly = new ContextAssemblyResult(
            variables: $variables,
            ragChunks: $ragChunks,
            provenanceSummary: $originalHealthContext !== ($variables['health_context'] ?? null)
                ? [...$provenanceSummary, 'health_context_degraded' => true]
                : $provenanceSummary,
            attachmentProvenance: $contextAssembly->attachmentProvenance,
        );
        AiRuntimeLimits::assertRenderedPromptWithinLimit($systemPrompt, $userPrompt, $capability);

        return [
            'contextAssembly' => $boundedAssembly,
            'systemPrompt' => $systemPrompt,
            'userPrompt' => $userPrompt,
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    private function fitHistory(
        string $systemTemplate,
        string $userTemplate,
        array $variables,
        AiCapabilityDefinition $capability,
    ): array {
        $history = trim((string) $variables['conversation_history']);
        $groups = $history === '' ? [] : (preg_split('/\R{2,}/u', $history) ?: []);

        while (true) {
            $variables['conversation_history'] = implode("\n\n", $groups);
            [$systemPrompt, $userPrompt] = $this->prompts($systemTemplate, $userTemplate, $variables);
            if ($this->fits($systemPrompt, $userPrompt, $capability) || $groups === []) {
                return [$variables, $systemPrompt, $userPrompt];
            }

            array_shift($groups);
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    private function fitHealthContext(
        string $systemTemplate,
        string $userTemplate,
        array $variables,
        AiCapabilityDefinition $capability,
    ): array {
        $healthContext = trim((string) $variables['health_context']);
        $low = 0;
        $high = mb_strlen($healthContext);
        $best = '';

        while ($low <= $high) {
            $length = intdiv($low + $high, 2);
            $candidate = $this->truncateAtLineBoundary($healthContext, $length);
            $variables['health_context'] = $candidate;
            [$systemPrompt, $userPrompt] = $this->prompts($systemTemplate, $userTemplate, $variables);

            if ($this->fits($systemPrompt, $userPrompt, $capability)) {
                $best = $candidate;
                $low = $length + 1;
            } else {
                $high = $length - 1;
            }
        }

        $variables['health_context'] = $best;
        [$systemPrompt, $userPrompt] = $this->prompts($systemTemplate, $userTemplate, $variables);

        return [$variables, $systemPrompt, $userPrompt];
    }

    private function truncateAtLineBoundary(string $value, int $maximumCharacters): string
    {
        if (mb_strlen($value) <= $maximumCharacters) {
            return $value;
        }

        $candidate = mb_substr($value, 0, $maximumCharacters);
        $lastNewline = mb_strrpos($candidate, "\n");
        if ($lastNewline !== false && $lastNewline >= intdiv($maximumCharacters, 2)) {
            $candidate = mb_substr($candidate, 0, $lastNewline);
        }

        return rtrim($candidate);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array{0: string, 1: string}
     */
    private function prompts(string $systemTemplate, string $userTemplate, array $variables): array
    {
        return [
            $this->renderer->render($systemTemplate, $variables),
            $this->renderer->render($userTemplate, $variables),
        ];
    }

    private function fits(string $systemPrompt, string $userPrompt, AiCapabilityDefinition $capability): bool
    {
        return AiRuntimeLimits::inputContextBudget($systemPrompt, $userPrompt, $capability)->fits();
    }
}
