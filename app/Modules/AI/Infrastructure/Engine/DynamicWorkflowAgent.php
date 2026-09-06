<?php

namespace App\Modules\AI\Infrastructure\Engine;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ArrayType;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

class DynamicWorkflowAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * @param  iterable<Tool>  $agentTools
     * @param  array<string, mixed>|null  $outputSchema
     */
    public function __construct(
        public string $instructionsText = '',
        public iterable $agentTools = [],
        public ?string $defaultProvider = null,
        public ?string $defaultModel = null,
        public ?int $resolvedMaxTokens = null,
        public ?int $resolvedMaxSteps = null,
        public ?array $outputSchema = null,
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->instructionsText;
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

    public function schema(JsonSchema $schema): array
    {
        if (! is_array($this->outputSchema) || ($this->outputSchema['type'] ?? null) !== 'object') {
            return [];
        }

        return $this->objectProperties($schema, $this->outputSchema);
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

    public function withMaxSteps(?int $maxSteps): static
    {
        $clone = clone $this;
        $clone->resolvedMaxSteps = $maxSteps;

        return $clone;
    }

    public function maxSteps(): ?int
    {
        return $this->resolvedMaxSteps;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, Type>
     */
    private function objectProperties(JsonSchema $schema, array $definition): array
    {
        $required = array_fill_keys(array_map('strval', (array) ($definition['required'] ?? [])), true);
        $properties = [];

        foreach ((array) ($definition['properties'] ?? []) as $name => $propertyDefinition) {
            if (! is_array($propertyDefinition)) {
                continue;
            }

            $property = $this->type($schema, $propertyDefinition);
            if (! $property instanceof Type) {
                continue;
            }

            if (isset($required[(string) $name])) {
                $property->required();
            }

            $properties[(string) $name] = $property;
        }

        return $properties;
    }

    /** @param array<string, mixed> $definition */
    private function type(JsonSchema $schema, array $definition): ?Type
    {
        if (isset($definition['anyOf']) && is_array($definition['anyOf'])) {
            $types = [];
            $nullable = false;

            foreach ($definition['anyOf'] as $branch) {
                if (! is_array($branch)) {
                    continue;
                }

                if (($branch['type'] ?? null) === 'null') {
                    $nullable = true;

                    continue;
                }

                $branchType = $this->type($schema, $branch);
                if ($branchType instanceof Type) {
                    $types[] = $branchType;
                }
            }

            if ($types === []) {
                return null;
            }

            if (count($types) === 1) {
                return $nullable ? $types[0]->nullable() : $types[0];
            }

            $anyOf = $schema->anyOf($types);

            return $nullable ? $anyOf->nullable() : $anyOf;
        }

        $declaredType = $definition['type'] ?? null;
        if (is_array($declaredType)) {
            $nullable = in_array('null', $declaredType, true);
            $types = array_values(array_filter($declaredType, static fn (mixed $type): bool => $type !== 'null'));
            if (count($types) !== 1 || ! is_string($types[0])) {
                return null;
            }

            $definition['type'] = $types[0];
            $type = $this->type($schema, $definition);

            return $type instanceof Type && $nullable ? $type->nullable() : $type;
        }

        if (! is_string($declaredType)) {
            return null;
        }

        $type = match ($declaredType) {
            'array' => $schema->array(),
            'boolean' => $schema->boolean(),
            'integer' => $schema->integer(),
            'number' => $schema->number(),
            'object' => $schema->object($this->objectProperties($schema, $definition)),
            'string' => $schema->string(),
            default => null,
        };

        if ($type === null) {
            return null;
        }

        if ($type instanceof ArrayType
            && isset($definition['items'])
            && is_array($definition['items'])) {
            $items = $this->type($schema, $definition['items']);
            if ($items instanceof Type) {
                $type->items($items);
            }
        }

        if (isset($definition['enum']) && is_array($definition['enum'])) {
            $type->enum(array_values($definition['enum']));
        }

        return $type;
    }
}
