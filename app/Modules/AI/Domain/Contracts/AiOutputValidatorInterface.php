<?php

namespace App\Modules\AI\Domain\Contracts;

interface AiOutputValidatorInterface
{
    /**
     * @param  array<string, mixed>|string|null  $output
     * @param  array<string, mixed>|null  $schema
     * @param  array<string, mixed>  $context
     */
    public function validate(array|string|null $output, ?array $schema, array $context = []): bool;

    public function getValidationError(): ?string;
}
