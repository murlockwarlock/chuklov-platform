<?php

namespace App\Modules\AI\Domain\Contracts;

interface AiToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    public function isReadOnly(): bool;

    /** @return array<string, mixed> */
    public function getInputSchema(): array;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(int $organizationId, array $input): array;
}
