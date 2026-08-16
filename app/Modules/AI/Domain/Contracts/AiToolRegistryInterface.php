<?php

namespace App\Modules\AI\Domain\Contracts;

interface AiToolRegistryInterface
{
    public function register(AiToolInterface $tool): void;

    public function get(string $name): ?AiToolInterface;

    /** @return array<string, AiToolInterface> */
    public function all(): array;
}
