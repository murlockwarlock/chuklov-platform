<?php

namespace App\Modules\AI\Infrastructure\ModelDiscovery;

use App\Modules\AI\Domain\Registry\AiModelDefinition;

final readonly class AiModelDiscoveryResult
{
    /**
     * @param  list<array<string, mixed>>  $definitions
     */
    public function __construct(
        public array $definitions,
        public bool $stale = false,
        public ?string $error = null,
    ) {}

    /** @return list<AiModelDefinition> */
    public function models(): array
    {
        $models = [];
        foreach ($this->definitions as $definition) {
            try {
                $models[] = AiModelDefinition::fromArray($definition);
            } catch (\Throwable) {
            }
        }

        return $models;
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }
}
