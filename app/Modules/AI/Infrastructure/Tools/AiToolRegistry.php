<?php

namespace App\Modules\AI\Infrastructure\Tools;

use App\Modules\AI\Domain\Contracts\AiToolInterface;
use App\Modules\AI\Domain\Contracts\AiToolRegistryInterface;

class AiToolRegistry implements AiToolRegistryInterface
{
    /** @var array<string, AiToolInterface> */
    private array $tools = [];

    /** @param list<AiToolInterface> $initialTools */
    public function __construct(array $initialTools = [])
    {
        foreach ($initialTools as $tool) {
            $this->register($tool);
        }
    }

    public function register(AiToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    public function get(string $name): ?AiToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /** @return array<string, AiToolInterface> */
    public function all(): array
    {
        return $this->tools;
    }
}
